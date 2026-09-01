<?php
/**
 * PayKaro — domain service (tenant-aware + auth).
 *
 * Every query is scoped to the current business (tenant) so multi-tenant
 * isolation is enforced at the data layer, not just in the view. Auth (users,
 * sessions, password hashing) also lives here.
 */

class PayKaro
{
	/** @var array */
	private array $config;
	/** @var PDO */
	private PDO $db;
	/** @var int|null Current tenant (business) id. */
	private ?int $tenantId = null;

	public function __construct( array $config, PDO $db ) {
		$this->config = $config;
		$this->db     = $db;
	}

	/* ================================================================== */
	/* Auth                                                              */
	/* ================================================================== */

	public function createUser( int $businessId, string $name, string $email, string $password = null, string $role = 'owner', string $provider = 'email', string $googleId = '', string $avatarUrl = '' ): int {
		$hash = null === $password ? null : password_hash( $password, PASSWORD_DEFAULT );
		$stmt = $this->db->prepare(
			'INSERT INTO users (business_id,name,email,password,role,provider,google_id,avatar_url) VALUES (?,?,?,?,?,?,?,?)'
		);
		$stmt->execute( array( $businessId, $name, $email, $hash, $role, $provider, $googleId ?: null, $avatarUrl ?: null ) );
		return (int) $this->db->lastInsertId();
	}

	/**
	 * Sign-up: create a business (tenant) and its owner user together.
	 * Returns the user row, or null if the email is already taken.
	 */
	public function registerBusiness( array $d ): ?array {
		$email    = strtolower( trim( (string) ( $d['email'] ?? '' ) ) );
		$name     = trim( (string) ( $d['name'] ?? '' ) );
		$password = (string) ( $d['password'] ?? '' );
		$bizName  = trim( (string) ( $d['business_name'] ?? '' ) );
		if ( '' === $email || '' === $password ) {
			return null;
		}
		if ( $this->userByEmail( $email ) ) {
			return null; // email in use
		}
		$biz = $this->createBusiness( '' !== $bizName ? $bizName : $name );
		$id  = $this->createUser( (int) $biz['id'], '' !== $name ? $name : 'Owner', $email, $password, 'owner' );
		return $this->user( $id );
	}

	/** Find a user by email, or null. */
	public function userByEmail( string $email ): ?array {
		$stmt = $this->db->prepare( 'SELECT * FROM users WHERE email=?' );
		$stmt->execute( array( strtolower( trim( $email ) ) ) );
		return $stmt->fetch() ?: null;
	}

	/** Find a user by their Google subject id, or null. */
	public function userByGoogleId( string $googleId ): ?array {
		$stmt = $this->db->prepare( 'SELECT * FROM users WHERE google_id=? AND provider=\'google\'' );
		$stmt->execute( array( $googleId ) );
		return $stmt->fetch() ?: null;
	}

	/**
	 * Match or provision a user from a Google profile.
	 *
	 * Link order:
	 *  1. existing user whose google_id matches;
	 *  2. existing user with the same email (attach google_id to it);
	 *  3. otherwise create a business + owner.
	 * Returns the user row, or null on invalid input.
	 */
	public function findOrCreateGoogleUser( array $profile ): ?array {
		$googleId = (string) ( $profile['google_id'] ?? '' );
		$email    = strtolower( trim( (string) ( $profile['email'] ?? '' ) ) );
		$name     = trim( (string) ( $profile['name'] ?? '' ) );
		$avatar   = (string) ( $profile['avatar_url'] ?? '' );
		if ( '' === $googleId || '' === $email ) {
			return null;
		}
		// 1. By google_id.
		$u = $this->userByGoogleId( $googleId );
		if ( $u ) {
			return $u;
		}
		// 2. Same email — link the google account.
		$u = $this->userByEmail( $email );
		if ( $u ) {
			$stmt = $this->db->prepare( "UPDATE users SET provider='google', google_id=?, avatar_url=COALESCE(NULLIF(?, ''), avatar_url) WHERE id=?" );
			$stmt->execute( array( $googleId, $avatar, (int) $u['id'] ) );
			return $this->user( (int) $u['id'] );
		}
		// 3. Brand new — create a business + owner.
		$biz = $this->createBusiness( '' !== $name ? $name : 'My Business' );
		$id  = $this->createUser( (int) $biz['id'], '' !== $name ? $name : 'Owner', $email, null, 'owner', 'google', $googleId, $avatar );
		return $this->user( $id );
	}

	/** Find a user by email + verify password. Returns user row or null. */
	public function authenticate( string $email, string $password ): ?array {
		$u = $this->userByEmail( $email );
		// OAuth-only users have a NULL password and cannot sign in via the form.
		if ( ! $u || null === $u['password'] || ! password_verify( $password, $u['password'] ) ) {
			return null;
		}
		return $u;
	}

	/** Create a session row and return the token. */
	public function createSession( int $userId ): string {
		$token   = bin2hex( random_bytes( 24 ) );
		$expires = date( 'Y-m-d H:i:s', time() + (int) $this->config['session_ttl'] );
		$stmt    = $this->db->prepare( 'INSERT INTO sessions (token,user_id,expires_at) VALUES (?,?,?)' );
		$stmt->execute( array( $token, $userId, $expires ) );
		return $token;
	}

	/** Resolve a session token to a user (and set the tenant). Null if invalid/expired. */
	public function userByToken( string $token ): ?array {
		$stmt = $this->db->prepare( 'SELECT u.*, s.token, s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?' );
		$stmt->execute( array( $token ) );
		$u = $stmt->fetch();
		if ( ! $u ) {
			return null;
		}
		// Expire.
		if ( strtotime( $u['expires_at'] ) < time() ) {
			$this->db->prepare( 'DELETE FROM sessions WHERE token=?' )->execute( array( $token ) );
			return null;
		}
		$this->tenantId = (int) $u['business_id'];
		return $u;
	}

	public function destroySession( string $token ): void {
		$this->db->prepare( 'DELETE FROM sessions WHERE token=?' )->execute( array( $token ) );
	}

	/** Store a one-time OAuth CSRF state with a short TTL (20 minutes). */
	public function storeOAuthState( string $state ): void {
		$expires = date( 'Y-m-d H:i:s', time() + 20 * 60 );
		$this->db->prepare( 'INSERT INTO oauth_states (state,expires_at) VALUES (?,?)' )->execute( array( $state, $expires ) );
		// Garbage collect expired states.
		$this->db->prepare( 'DELETE FROM oauth_states WHERE expires_at < datetime(\'now\')' )->execute();
	}

	/**
	 * Validate and consume a one-time OAuth CSRF state.
	 * Returns true (and deletes it) if it exists and hasn't expired.
	 */
	public function consumeOAuthState( string $state ): bool {
		$stmt = $this->db->prepare( "SELECT 1 FROM oauth_states WHERE state=? AND expires_at >= datetime('now')" );
		$stmt->execute( array( $state ) );
		if ( ! $stmt->fetch() ) {
			return false;
		}
		$this->db->prepare( 'DELETE FROM oauth_states WHERE state=?' )->execute( array( $state ) );
		return true;
	}

	public function user( int $id ): ?array {
		$stmt = $this->db->prepare( 'SELECT * FROM users WHERE id=?' );
		$stmt->execute( array( $id ) );
		return $stmt->fetch() ?: null;
	}

	/* ================================================================== */
	/* Tenant scoping                                                   */
	/* ================================================================== */

	/** Set the current tenant. */
	public function setTenant( ?int $id ): void {
		$this->tenantId = $id;
	}

	/** Current tenant id or null. */
	public function tenantId(): ?int {
		return $this->tenantId;
	}

	/** Ensure a business row exists and is the current tenant. */
	public function business(): array {
		if ( null === $this->tenantId ) {
			// Fall back to the first business (demo when no login is used).
			$row = $this->db->query( 'SELECT * FROM businesses ORDER BY id LIMIT 1' )->fetch();
			if ( $row ) {
				$this->tenantId = (int) $row['id'];
				return $row;
			}
			return $this->createBusiness( 'My Business' );
		}
		$stmt = $this->db->prepare( 'SELECT * FROM businesses WHERE id=?' );
		$stmt->execute( array( $this->tenantId ) );
		$row = $stmt->fetch();
		if ( $row ) {
			return $row;
		}
		// Tenant row missing — create it.
		return $this->createBusiness( 'My Business' );
	}

	/** Create a new business (tenant) and make it current. Returns its row. */
	public function createBusiness( string $name ): array {
		$this->db->prepare( 'INSERT INTO businesses (name) VALUES (?)' )->execute( array( $name ) );
		$id = (int) $this->db->lastInsertId();
		$this->tenantId = $id;
		$stmt = $this->db->prepare( 'SELECT * FROM businesses WHERE id=?' );
		$stmt->execute( array( $id ) );
		return $stmt->fetch();
	}

	public function updateBusiness( array $d ): void {
		$b = $this->business();
		$stmt = $this->db->prepare(
			'UPDATE businesses SET name=?, gstin=?, pan=?, udyam_no=?, bank_name=?, bank_acc_no=?, bank_ifsc=?, treds_registered=? WHERE id=?'
		);
		$stmt->execute( array(
			$d['name'] ?? $b['name'], $d['gstin'] ?? '', $d['pan'] ?? '', $d['udyam_no'] ?? '',
			$d['bank_name'] ?? '', $d['bank_acc_no'] ?? '', $d['bank_ifsc'] ?? '',
			(int) ( $d['treds_registered'] ?? 0 ), $b['id'],
		) );
	}

	/** tenant guard helper. */
	private function tid(): int {
		return (int) $this->business()['id'];
	}

	/* ================================================================== */
	/* Buyers                                                            */
	/* ================================================================== */

	public function buyers(): array {
		$stmt = $this->db->prepare( 'SELECT * FROM buyers WHERE business_id=? ORDER BY name' );
		$stmt->execute( array( $this->tid() ) );
		$rows = $stmt->fetchAll();
		foreach ( $rows as &$r ) {
			$r['outstanding'] = $this->buyerOutstanding( $r['id'] );
		}
		return $rows;
	}

	public function buyer( int $id ): ?array {
		$stmt = $this->db->prepare( 'SELECT * FROM buyers WHERE id=? AND business_id=?' );
		$stmt->execute( array( $id, $this->tid() ) );
		$r = $stmt->fetch();
		if ( $r ) {
			$r['outstanding'] = $this->buyerOutstanding( $id );
		}
		return $r ?: null;
	}

	private function buyerOutstanding( int $buyerId ): float {
		$stmt = $this->db->prepare( "SELECT COALESCE(SUM(total_amount),0) s FROM invoices WHERE buyer_id=? AND status NOT IN ('settled','draft')" );
		$stmt->execute( array( $buyerId ) );
		return (float) $stmt->fetchColumn();
	}

	public function createBuyer( array $d ): int {
		$stmt = $this->db->prepare( 'INSERT INTO buyers (business_id,name,gstin,type,treds_onboarded) VALUES (?,?,?,?,?)' );
		$stmt->execute( array( $this->tid(), $d['name'], $d['gstin'] ?? '', $d['type'] ?? 'private', $d['treds_onboarded'] ?? 'unknown' ) );
		return (int) $this->db->lastInsertId();
	}

	/* ================================================================== */
	/* Invoices                                                          */
	/* ================================================================== */

	public function invoices( array $filters = array() ): array {
		$sql = 'SELECT i.*, b.name AS buyer_name, b.type AS buyer_type, b.treds_onboarded
		        FROM invoices i JOIN buyers b ON b.id=i.buyer_id WHERE i.business_id=?';
		$p   = array( $this->tid() );

		if ( ! empty( $filters['status'] ) && 'all' !== $filters['status'] ) {
			$sql .= ' AND i.status=?';
			$p[]  = $filters['status'];
		}
		if ( ! empty( $filters['buyer_id'] ) ) {
			$sql .= ' AND i.buyer_id=?';
			$p[]  = (int) $filters['buyer_id'];
		}
		$sql .= ' ORDER BY i.invoice_date DESC, i.id DESC';
		$stmt = $this->db->prepare( $sql );
		$stmt->execute( $p );
		$rows = $stmt->fetchAll();
		foreach ( $rows as &$r ) {
			$r['overdue_days'] = $this->overdueDays( $r );
			$r['interest']     = $this->interest( $r );
			$r['ageing']       = $this->ageingBucket( $r );
			$r['readiness']    = $this->readiness( $r );
			$r['treds']        = $this->tredsStatus( $r );
			$r['balance']      = $this->balance( $r );
		}
		return $rows;
	}

	public function invoice( int $id ): ?array {
		$stmt = $this->db->prepare( 'SELECT i.*, b.name AS buyer_name, b.type AS buyer_type, b.treds_onboarded
		        FROM invoices i JOIN buyers b ON b.id=i.buyer_id WHERE i.id=? AND i.business_id=?' );
		$stmt->execute( array( $id, $this->tid() ) );
		$r = $stmt->fetch();
		if ( ! $r ) {
			return null;
		}
		$r['overdue_days'] = $this->overdueDays( $r );
		$r['interest']     = $this->interest( $r );
		$r['ageing']       = $this->ageingBucket( $r );
		$r['readiness']    = $this->readiness( $r );
		$r['treds']        = $this->tredsStatus( $r );
		$r['balance']      = $this->balance( $r );
		$r['evidences']    = $this->evidences( $id );
		$r['payments']     = $this->payments( $id );
		$r['financing']    = $this->financing( $id );
		$r['disputes']     = $this->disputes( $id );
		return $r;
	}

	public function evidences( int $invoiceId ): array {
		$stmt = $this->db->prepare( 'SELECT * FROM invoice_evidences WHERE invoice_id=? ORDER BY id' );
		$stmt->execute( array( $invoiceId ) );
		$rows = $stmt->fetchAll();
		if ( ! $rows ) {
			$config = $this->config;
			$all    = array_values( array_unique( array_merge( $config['required_evidence'], array( 'contract' ) ) ) );
			$insert = $this->db->prepare( 'INSERT INTO invoice_evidences (invoice_id,type,present) VALUES (?,?,0)' );
			foreach ( $all as $type ) {
				$insert->execute( array( $invoiceId, $type ) );
			}
			$rows = $this->evidences( $invoiceId );
		}
		return $rows;
	}

	public function createInvoice( array $d ): int {
		$base  = (float) $d['base_amount'];
		$tax   = (float) ( $d['tax_amount'] ?? ( $base * $this->config['default_tax_rate'] / 100 ) );
		$total = $base + $tax;
		$due   = $this->addDays( $d['invoice_date'], (int) $this->config['msme_due_days'] );
		$stmt  = $this->db->prepare(
			'INSERT INTO invoices (business_id,buyer_id,number,invoice_date,due_date,base_amount,tax_amount,total_amount,status,notes)
			 VALUES (?,?,?,?,?,?,?,?,?,?)'
		);
		$stmt->execute( array( $this->tid(), (int) $d['buyer_id'], $d['number'], $d['invoice_date'], $due, $base, $tax, $total, 'raised', $d['notes'] ?? '' ) );
		$id = (int) $this->db->lastInsertId();
		$this->seedEvidences( $id );
		$this->createAlerts( $id );
		return $id;
	}

	/** Update an existing (tenant-owned) invoice. Returns true on success. */
	public function updateInvoice( int $id, array $d ): bool {
		$inv = $this->invoice( $id );
		if ( ! $inv ) {
			return false;
		}
		$base = (float) ( $d['base_amount'] ?? $inv['base_amount'] );
		$tax  = (float) ( $d['tax_amount'] ?? ( $base * $this->config['default_tax_rate'] / 100 ) );
		$total = $base + $tax;
		$date = $d['invoice_date'] ?? $inv['invoice_date'];
		$due  = $this->addDays( $date, (int) $this->config['msme_due_days'] );
		$stmt = $this->db->prepare(
			'UPDATE invoices SET number=?, buyer_id=?, invoice_date=?, due_date=?, base_amount=?, tax_amount=?, total_amount=?, notes=? WHERE id=? AND business_id=?'
		);
		$stmt->execute( array(
			$d['number'] ?? $inv['number'],
			(int) ( $d['buyer_id'] ?? $inv['buyer_id'] ),
			$date, $due, $base, $tax, $total,
			$d['notes'] ?? $inv['notes'],
			$id, $this->tid(),
		) );
		return true;
	}

	private function seedEvidences( int $id ): void {
		$config = $this->config;
		$all    = array_values( array_unique( array_merge( $config['required_evidence'], array( 'contract' ) ) ) );
		$insert = $this->db->prepare( 'INSERT INTO invoice_evidences (invoice_id,type,present) VALUES (?,?,0)' );
		foreach ( $all as $type ) {
			$insert->execute( array( $id, $type ) );
		}
	}

	public function setEvidence( int $invoiceId, string $type, bool $present ): void {
		$stmt = $this->db->prepare( 'UPDATE invoice_evidences SET present=? WHERE invoice_id=? AND type=?' );
		$stmt->execute( array( $present ? 1 : 0, $invoiceId, $type ) );
	}

	public function setStatus( int $id, string $status ): void {
		$valid = array( 'draft', 'raised', 'accepted', 'financed', 'settled', 'disputed' );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}
		$stmt = $this->db->prepare( 'UPDATE invoices SET status=?, approval_date=COALESCE(approval_date,?) WHERE id=? AND business_id=?' );
		$now  = date( 'Y-m-d' );
		if ( 'settled' === $status ) {
			$stmt->execute( array( $status, $now, $id, $this->tid() ) );
			$this->db->prepare( 'UPDATE invoices SET paid_date=? WHERE id=?' )->execute( array( $now, $id ) );
		} elseif ( in_array( $status, array( 'accepted', 'financed' ), true ) ) {
			$stmt->execute( array( $status, $now, $id, $this->tid() ) );
		} else {
			$stmt->execute( array( $status, null, $id, $this->tid() ) );
		}
	}

	/* ================================================================== */
	/* Payments / financing / disputes                                   */
	/* ================================================================== */

	public function payments( int $invoiceId ): array {
		$stmt = $this->db->prepare( 'SELECT * FROM payments WHERE invoice_id=? ORDER BY paid_on DESC, id DESC' );
		$stmt->execute( array( $invoiceId ) );
		return $stmt->fetchAll();
	}

	public function paidTotal( int $invoiceId ): float {
		$stmt = $this->db->prepare( 'SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?' );
		$stmt->execute( array( $invoiceId ) );
		return (float) $stmt->fetchColumn();
	}

	public function balance( array $inv ): float {
		if ( 'settled' === $inv['status'] ) {
			return 0.0;
		}
		$paid = $this->paidTotal( (int) $inv['id'] );
		return max( 0, round( (float) $inv['total_amount'] - $paid, 2 ) );
	}

	public function recordPayment( int $invoiceId, array $d ): void {
		$amount = (float) $d['amount'];
		if ( $amount <= 0 ) {
			return;
		}
		$inv  = $this->invoice( $invoiceId );
		if ( ! $inv ) {
			return;
		}
		$paid = $this->paidTotal( $invoiceId );
		$bal  = (float) $inv['total_amount'] - $paid;
		$stmt = $this->db->prepare( 'INSERT INTO payments (invoice_id,amount,paid_on,method,reference) VALUES (?,?,?,?,?)' );
		$stmt->execute( array( $invoiceId, min( $amount, $bal ), $d['paid_on'] ?? date( 'Y-m-d' ), $d['method'] ?? '', $d['reference'] ?? '' ) );
		if ( (float) $inv['total_amount'] - $this->paidTotal( $invoiceId ) <= 0.001 ) {
			$this->setStatus( $invoiceId, 'settled' );
		}
	}

	public function financing( int $invoiceId ): array {
		$stmt = $this->db->prepare( 'SELECT * FROM financing WHERE invoice_id=? ORDER BY disbursed_on DESC, id DESC' );
		$stmt->execute( array( $invoiceId ) );
		return $stmt->fetchAll();
	}

	public function recordFinancing( int $invoiceId, array $d ): void {
		$inv = $this->invoice( $invoiceId );
		if ( ! $inv ) {
			return;
		}
		$rate = (float) ( $d['discount_rate'] ?? 1.5 );
		$amt  = (float) ( $d['amount_disbursed'] ?? $inv['total_amount'] );
		$this->db->prepare( 'INSERT INTO financing (invoice_id,financier,discount_rate,amount_disbursed,disbursed_on,status) VALUES (?,?,?,?,?,?)' )
			->execute( array( $invoiceId, $d['financier'] ?? 'Bank', $rate, $amt, $d['disbursed_on'] ?? date( 'Y-m-d' ), 'disbursed' ) );
		$this->setStatus( $invoiceId, 'financed' );
	}

	public function disputes( int $invoiceId ): array {
		$stmt = $this->db->prepare( 'SELECT * FROM disputes WHERE invoice_id=? ORDER BY filed_on DESC, id DESC' );
		$stmt->execute( array( $invoiceId ) );
		return $stmt->fetchAll();
	}

	public function startDispute( int $invoiceId, array $d ): int {
		$forum = $d['forum'] ?? 'msefc';
		$inv   = $this->invoice( $invoiceId );
		$deadline = null;
		if ( $inv && 'msefc' === $forum ) {
			$deadline = date( 'Y-m-d', strtotime( $inv['due_date'] ) + 45 * 86400 );
		} elseif ( $inv && 'arbitration' === $forum ) {
			$deadline = date( 'Y-m-d', strtotime( $inv['due_date'] ) + 90 * 86400 );
		}
		$this->db->prepare( 'INSERT INTO disputes (invoice_id,forum,stage,filed_on,deadline_on) VALUES (?,?,?,?,?)' )
			->execute( array( $invoiceId, $forum, 'filing', date( 'Y-m-d' ), $deadline ) );
		$this->setStatus( $invoiceId, 'disputed' );
		$id = (int) $this->db->lastInsertId();
		$this->db->prepare( 'INSERT INTO alerts (business_id,invoice_id,type,message) VALUES (?,?,?,?)' )
			->execute( array( $this->tid(), $invoiceId, 'dispute', 'Dispute filed. Deadline:' . ( $deadline ?: '—' ) . ' — gather the evidence packet.' ) );
		return $id;
	}

	public function daysTo( ?string $date ): ?int {
		if ( ! $date ) {
			return null;
		}
		$t = strtotime( $date );
		if ( ! $t ) {
			return null;
		}
		return (int) floor( ( $t - strtotime( $this->today() ) ) / 86400 );
	}

	public function claimPacket( int $invoiceId ): array {
		$inv = $this->invoice( $invoiceId );
		if ( ! $inv ) {
			return array();
		}
		$rows   = array();
		$rows[] = array( 'label' => 'Invoice', 'value' => $inv['number'] . ' dated ' . $inv['invoice_date'] );
		$rows[] = array( 'label' => 'Buyer', 'value' => $inv['buyer_name'] . ' (' . $inv['buyer_type'] . ')' );
		$rows[] = array( 'label' => 'GSTIN', 'value' => $this->buyerGstin( (int) $inv['buyer_id'] ) );
		$rows[] = array( 'label' => 'Invoice amount', 'value' => '₹' . number_format( (float) $inv['total_amount'], 2 ) );
		$rows[] = array( 'label' => 'Due date', 'value' => $inv['due_date'] );
		$rows[] = array( 'label' => 'Days overdue', 'value' => (string) $inv['overdue_days'] );
		$rows[] = array( 'label' => 'Interest due (3× bank rate)', 'value' => '₹' . number_format( (float) $inv['interest'], 2 ) );
		foreach ( $inv['evidences'] as $ev ) {
			$rows[] = array(
				'label' => ucfirst( str_replace( '_', ' ', $ev['type'] ) ),
				'value' => $ev['present'] ? 'Attachment present ✓' : 'MISSING — attach before filing',
			);
		}
		$row   = $this->disputes( $invoiceId );
		$rows[] = array( 'label' => 'File before', 'value' => $row ? ( $row[0]['deadline_on'] ?: '—' ) : '—' );
		return $rows;
	}

	private function buyerGstin( int $buyerId ): string {
		$stmt = $this->db->prepare( 'SELECT gstin FROM buyers WHERE id=?' );
		$stmt->execute( array( $buyerId ) );
		return (string) $stmt->fetchColumn();
	}

	/* ================================================================== */
	/* Business rules                                                    */
	/* ================================================================== */

	public function overdueDays( array $inv ): int {
		if ( 'settled' === $inv['status'] ) {
			return 0;
		}
		$due = strtotime( $inv['due_date'] );
		if ( ! $due ) {
			return 0;
		}
		$diff = (int) floor( ( strtotime( $this->today() ) - $due ) / 86400 );
		return max( 0, $diff );
	}

	public function interest( array $inv ): float {
		$days = $this->overdueDays( $inv );
		if ( $days <= 0 ) {
			return 0.0;
		}
		$rate  = (float) $this->config['bank_rate'] * (int) $this->config['interest_multiplier'];
		$daily = $rate / 100 / 365;
		return round( (float) $inv['total_amount'] * $daily * $days, 2 );
	}

	public function ageingBucket( array $inv ): string {
		$d = $this->overdueDays( $inv );
		if ( $d <= 0 ) {
			return 'Current';
		}
		if ( $d <= 30 ) {
			return '1–30d';
		}
		if ( $d <= 60 ) {
			return '31–60d';
		}
		if ( $d <= 90 ) {
			return '61–90d';
		}
		return '90+';
	}

	public function readiness( array $inv ): int {
		$config  = $this->config;
		$ev      = $this->evidences( (int) $inv['id'] );
		$required = $config['required_evidence'];
		$byType  = array();
		foreach ( $ev as $e ) {
			$byType[ $e['type'] ] = (int) $e['present'];
		}
		$present = 0;
		foreach ( $required as $t ) {
			if ( ! empty( $byType[ $t ] ) ) {
				$present++;
			}
		}
		$score = ( $present / max( 1, count( $required ) ) ) * 70;
		if ( 'yes' === ( $inv['treds_onboarded'] ?? '' ) ) {
			$score += 20;
		} elseif ( 'no' === ( $inv['treds_onboarded'] ?? '' ) ) {
			$score += 5;
		}
		if ( $this->overdueDays( $inv ) > 0 ) {
			$score += 10;
		}
		return (int) min( 100, round( $score ) );
	}

	public function tredsStatus( array $inv ): string {
		if ( in_array( $inv['status'], array( 'settled', 'draft' ), true ) ) {
			return 'na';
		}
		if ( 'financed' === $inv['status'] ) {
			return 'financed';
		}
		if ( 'disputed' === $inv['status'] ) {
			return 'ineligible';
		}
		if ( 'yes' !== ( $inv['treds_onboarded'] ?? '' ) ) {
			return 'pending_buyer_onboard';
		}
		return 'ready';
	}

	/* ================================================================== */
	/* Alerts / dashboard / reports                                       */
	/* ================================================================== */

	private function createAlerts( int $id ): void {
		$inv = $this->invoice( $id );
		if ( ! $inv ) {
			return;
		}
		$insert = $this->db->prepare( 'INSERT INTO alerts (business_id,invoice_id,type,message) VALUES (?,?,?,?)' );
		if ( 'yes' !== ( $inv['treds_onboarded'] ?? '' ) ) {
			$insert->execute( array( $this->tid(), $id, 'treds', 'Buyer is not TReDS-onboarded — confirm before the invoice churns.' ) );
		}
	}

	public function alerts( int $limit = 6 ): array {
		$stmt = $this->db->prepare( 'SELECT a.*, i.number FROM alerts a LEFT JOIN invoices i ON i.id=a.invoice_id WHERE a.business_id=? AND a.read_at IS NULL ORDER BY a.id DESC LIMIT ?' );
		$stmt->bindValue( 1, $this->tid(), PDO::PARAM_INT );
		$stmt->bindValue( 2, $limit, PDO::PARAM_INT );
		$stmt->execute();
		return $stmt->fetchAll();
	}

	public function markAlertsRead(): void {
		$this->db->prepare( 'UPDATE alerts SET read_at=? WHERE business_id=? AND read_at IS NULL' )->execute( array( date( 'Y-m-d H:i:s' ), $this->tid() ) );
	}

	public function dashboard(): array {
		$invoices = $this->invoices();
		$total    = 0.0; $overdue = 0.0; $overdueCount = 0;
		$buckets  = array( 'Current' => 0.0, '1–30d' => 0.0, '31–60d' => 0.0, '61–90d' => 0.0, '90+' => 0.0 );
		$byStatus = array( 'raised' => 0, 'accepted' => 0, 'financed' => 0, 'settled' => 0, 'disputed' => 0 );

		foreach ( $invoices as $i ) {
			if ( 'draft' === $i['status'] ) {
				continue;
			}
			$bal = $this->balance( $i );
			$total += $bal;
			if ( 'settled' !== $i['status'] ) {
				$byStatus[ $i['status'] ] = ( $byStatus[ $i['status'] ] ?? 0 ) + 1;
			}
			if ( $i['overdue_days'] > 0 && 'settled' !== $i['status'] ) {
				$overdue += $bal;
				$overdueCount++;
			}
			$buckets[ $i['ageing'] ] += $bal;
		}

		$in30d = 0.0;
		foreach ( $invoices as $i ) {
			if ( 'settled' === $i['status'] ) {
				continue;
			}
			$dueTs = strtotime( $i['due_date'] );
			if ( $dueTs && $dueTs <= strtotime( $this->today() . ' +30 days' ) && $dueTs >= strtotime( $this->today() . ' -1 days' ) ) {
				$in30d += (float) $i['total_amount'];
			}
		}

		return array(
			'total'        => $total,
			'overdue'      => $overdue,
			'overdueCount' => $overdueCount,
			'buckets'      => $buckets,
			'byStatus'     => $byStatus,
			'in30d'        => $in30d,
			'count'        => count( $invoices ),
			'interest'     => array_sum( array_map( fn( $i ) => $i['interest'], $invoices ) ),
			'financeReady' => count( array_filter( $invoices, fn( $i ) => 'ready' === $i['treds'] ) ),
		);
	}

	public function financeQueue(): array {
		return array_values( array_filter(
			$this->invoices(),
			fn( $i ) => in_array( $i['treds'], array( 'ready', 'pending_buyer_onboard' ), true )
		) );
	}

	/* ================================================================== */
	/* Helpers                                                           */
	/* ================================================================== */

	private function today(): string {
		return date( 'Y-m-d' );
	}

	private function addDays( string $date, int $days ): string {
		$t = strtotime( $date );
		return $t ? date( 'Y-m-d', $t + $days * 86400 ) : $date;
	}
}
