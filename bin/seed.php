<?php
/**
 * PayKaro — install + seed.
 *
 * Creates the schema and, if empty, inserts two demo businesses, each with a
 * user (to demonstrate multi-tenant isolation) and data. Idempotent.
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../PayKaro.php';

$config = require __DIR__ . '/../config.php';
$db     = paykaro_db();

$schema = file_get_contents( dirname( __DIR__ ) . '/schema.sql' );
if ( false === $schema ) {
	fwrite( STDERR, "Could not read schema.sql\n" );
	exit( 1 );
}
$db->exec( $schema );
echo "Schema ensured.\n";

$app = new PayKaro( $config, $db );

$users = (int) $db->query( 'SELECT COUNT(*) FROM users' )->fetchColumn();
if ( $users > 0 ) {
	echo "Users already present; skipping demo seed.\n";
	exit( 0 );
}

function d( string $off ): string {
	// $off is a signed day offset like "-92" (92 days before today).
	return date( 'Y-m-d', strtotime( $off . ' days' ) );
}

// ---- business 1: Shree Precision (owner demo) ----
$b1Id = $app->createBusiness( 'Shree Precision Components' )['id'];
$app->setTenant( $b1Id );
$app->updateBusiness( array(
	'name' => 'Shree Precision Components', 'gstin' => '36AAACS1234F1Z5', 'pan' => 'AAACS1234F',
	'udyam_no' => 'UDYAM-TS-12-3456789', 'bank_name' => 'HDFC Bank', 'bank_acc_no' => '50100234567890',
	'bank_ifsc' => 'HDFC0001234', 'treds_registered' => 1,
) );
$u1 = $app->createUser( $b1Id, 'Sunita Rao', 'sunita@shreeprecision.in', 'demo1234', 'owner' );

$buyers1 = array(
	array( 'Bharat Heavy Electricals Ltd', '36AABCB1234C1Z3', 'cpse', 'yes' ),
	array( 'Telangana State Powergen', '36AAACT5678D1Z8', 'psu', 'yes' ),
	array( 'Orbit Auto Components Pvt Ltd', '36AAGCO9876E1Z2', 'private', 'no' ),
	array( 'Hydrofit Engineering LLP', '36AAJFH2468F1Z9', 'private', 'unknown' ),
);
$bid1 = array();
foreach ( $buyers1 as $b ) {
	$bid1[] = $app->createBuyer( array( 'name' => $b[0], 'gstin' => $b[1], 'type' => $b[2], 'treds_onboarded' => $b[3] ) );
}

$data = array(
	array( $bid1[0], 'INV-2026-001', '-92', 485000, 'settled',  array( 'po', 'delivery_ack', 'grn', 'invoice_copy', 'contract' ) ),
	array( $bid1[0], 'INV-2026-002', '-48', 210000, 'accepted', array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[0], 'INV-2026-003', '-70', 360000, 'accepted', array( 'po', 'delivery_ack', 'grn', 'invoice_copy', 'contract' ) ),
	array( $bid1[0], 'INV-2026-004', '-26', 92000,  'raised',   array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[1], 'INV-2026-005', '-80', 640000, 'accepted', array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[1], 'INV-2026-006', '-33', 150000, 'raised',   array( 'po', 'delivery_ack', 'grn' ) ),
	array( $bid1[1], 'INV-2026-007', '-110', 820000, 'disputed', array( 'po', 'delivery_ack', 'grn', 'contract' ) ),
	array( $bid1[2], 'INV-2026-008', '-15', 76000,  'raised',   array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[2], 'INV-2026-009', '-60', 200000, 'accepted', array( 'po', 'delivery_ack', 'grn' ) ),
	array( $bid1[2], 'INV-2026-010', '-5',  45000,  'raised',   array( 'po', 'grn' ) ),
	array( $bid1[3], 'INV-2026-011', '-42', 130000, 'raised',   array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[3], 'INV-2026-012', '-100', 300000, 'accepted', array( 'po', 'delivery_ack', 'grn', 'contract' ) ),
	array( $bid1[0], 'INV-2026-013', '-18', 88000,  'financed', array( 'po', 'delivery_ack', 'grn', 'invoice_copy', 'contract' ) ),
	array( $bid1[1], 'INV-2026-014', '-12', 64000,  'accepted', array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid1[2], 'INV-2026-015', '-8',  32000,  'raised',   array( 'po', 'invoice_copy' ) ),
);

foreach ( $data as $row ) {
	$buyerId = $row[0]; $num = $row[1]; $off = $row[2]; $base = $row[3]; $status = $row[4]; $ev = $row[5] ?? array();
	$invoiceDate = d( $off );
	$tax = $base * ( $config['default_tax_rate'] / 100 );
	$due = date( 'Y-m-d', strtotime( $invoiceDate ) + $config['msme_due_days'] * 86400 );
	$stmt = $db->prepare( 'INSERT INTO invoices (business_id,buyer_id,number,invoice_date,due_date,base_amount,tax_amount,total_amount,status,approval_date) VALUES (?,?,?,?,?,?,?,?,?,?)' );
	$approval = in_array( $status, array( 'accepted', 'financed', 'settled' ), true ) ? date( 'Y-m-d', strtotime( $invoiceDate ) + 12 * 86400 ) : null;
	$stmt->execute( array( $b1Id, $buyerId, $num, $invoiceDate, $due, $base, $tax, $base + $tax, $status, $approval ) );
	$invoiceId = (int) $db->lastInsertId();

	$all = array_values( array_unique( array_merge( $config['required_evidence'], array( 'contract' ) ) ) );
	$ins = $db->prepare( 'INSERT INTO invoice_evidences (invoice_id,type,present) VALUES (?,?,?)' );
	foreach ( $all as $type ) {
		$ins->execute( array( $invoiceId, $type, in_array( $type, $ev, true ) ? 1 : 0 ) );
	}
	if ( 'settled' === $status ) {
		$db->prepare( 'UPDATE invoices SET paid_date=? WHERE id=?' )->execute( array( date( 'Y-m-d', strtotime( $invoiceDate ) + 50 * 86400 ), $invoiceId ) );
	}
	if ( 'financed' === $status ) {
		$db->prepare( 'INSERT INTO financing (invoice_id,financier,discount_rate,amount_disbursed,disbursed_on) VALUES (?,?,?,?,?)' )
			->execute( array( $invoiceId, 'HDFC Bank', 1.5, $base + $tax, date( 'Y-m-d', strtotime( $invoiceDate ) + 20 * 86400 ) ) );
	}
	// Alert for invoices whose buyer isn't TReDS-onboarded (feeds "Needs attention").
	if ( ! in_array( $status, array( 'settled', 'draft' ), true ) ) {
		$bt = (string) $db->query( 'SELECT treds_onboarded FROM buyers WHERE id=' . (int) $buyerId )->fetchColumn();
		if ( 'yes' !== $bt ) {
			$db->prepare( 'INSERT INTO alerts (business_id,invoice_id,type,message) VALUES (?,?,?,?)' )
				->execute( array( $b1Id, $invoiceId, 'treds', 'Buyer is not TReDS-onboarded — confirm before the invoice churns.' ) );
		}
	}
}
echo "Seeded business 1 (Shree Precision) — 15 invoices, 1 user.\n";

// ---- business 2: MetRow Ceramics (second tenant, proves isolation) ----
$b2Id = $app->createBusiness( 'MetRow Ceramics' )['id'];
$app->setTenant( $b2Id );
$app->updateBusiness( array(
	'name' => 'MetRow Ceramics', 'gstin' => '29AAACM5678G1Z4', 'pan' => 'AAACM5678G',
	'udyam_no' => 'UDYAM-KA-11-7654321', 'bank_name' => 'SBI', 'bank_acc_no' => '30123456789',
	'bank_ifsc' => 'SBIN0004567', 'treds_registered' => 0,
) );
$u2 = $app->createUser( $b2Id, 'Farhan Ali', 'farhan@metrowceramics.in', 'demo1234', 'owner' );

$buyers2 = array(
	array( 'Delhi Metro Rail Corp', '07AADCM2222H1Z1', 'psu', 'yes' ),
	array( 'Urban Structures Pvt Ltd', '29AABFU3333K1Z7', 'private', 'unknown' ),
);
$bid2 = array();
foreach ( $buyers2 as $b ) {
	$bid2[] = $app->createBuyer( array( 'name' => $b[0], 'gstin' => $b[1], 'type' => $b[2], 'treds_onboarded' => $b[3] ) );
}
$data2 = array(
	array( $bid2[0], 'INV-2026-101', '-60', 540000, 'accepted', array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ) ),
	array( $bid2[0], 'INV-2026-102', '-20', 180000, 'raised',   array( 'po', 'delivery_ack', 'grn' ) ),
	array( $bid2[1], 'INV-2026-103', '-8',  64000,  'raised',   array( 'po', 'invoice_copy' ) ),
);
foreach ( $data2 as $row ) {
	$buyerId = $row[0]; $num = $row[1]; $off = $row[2]; $base = $row[3]; $status = $row[4]; $ev = $row[5] ?? array();
	$invoiceDate = d( $off );
	$tax = $base * ( $config['default_tax_rate'] / 100 );
	$due = date( 'Y-m-d', strtotime( $invoiceDate ) + $config['msme_due_days'] * 86400 );
	$stmt = $db->prepare( 'INSERT INTO invoices (business_id,buyer_id,number,invoice_date,due_date,base_amount,tax_amount,total_amount,status,approval_date) VALUES (?,?,?,?,?,?,?,?,?,?)' );
	$approval = in_array( $status, array( 'accepted', 'financed', 'settled' ), true ) ? date( 'Y-m-d', strtotime( $invoiceDate ) + 12 * 86400 ) : null;
	$stmt->execute( array( $b2Id, $buyerId, $num, $invoiceDate, $due, $base, $tax, $base + $tax, $status, $approval ) );
	$invoiceId = (int) $db->lastInsertId();
	$all = array_values( array_unique( array_merge( $config['required_evidence'], array( 'contract' ) ) ) );
	$ins = $db->prepare( 'INSERT INTO invoice_evidences (invoice_id,type,present) VALUES (?,?,?)' );
	foreach ( $all as $type ) {
		$ins->execute( array( $invoiceId, $type, in_array( $type, $ev, true ) ? 1 : 0 ) );
	}
	if ( ! in_array( $status, array( 'settled', 'draft' ), true ) ) {
		$bt = (string) $db->query( 'SELECT treds_onboarded FROM buyers WHERE id=' . (int) $buyerId )->fetchColumn();
		if ( 'yes' !== $bt ) {
			$db->prepare( 'INSERT INTO alerts (business_id,invoice_id,type,message) VALUES (?,?,?,?)' )
				->execute( array( $b2Id, $invoiceId, 'treds', 'Buyer is not TReDS-onboarded — confirm before the invoice churns.' ) );
		}
	}
}
echo "Seeded business 2 (MetRow Ceramics) — 3 invoices, 1 user.\n";
echo "Demo logins:\n";
echo "  sunita@shreeprecision.in / demo1234  (tenant 1)\n";
echo "  farhan@metrowceramics.in / demo1234  (tenant 2)\n";
echo "Done.\n";
