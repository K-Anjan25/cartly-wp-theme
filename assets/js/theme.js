/**
 * Cartly theme behaviour.
 *
 * Deliberately dependency-free: colour scheme, mobile drawer, filter sheet,
 * dismissible announcement and the ⌘K search shortcut. Mirrors the React
 * shell's interactions so both front ends feel identical.
 */
(function () {
	'use strict';

	var SCHEME_KEY = 'cartly-color-scheme';
	var ANNOUNCE_KEY = 'cartly-announce-dismissed';
	var i18n = window.cartlyI18n || { toLight: 'Switch to light mode', toDark: 'Switch to dark mode' };

	/* ------------------------------------------------------ colour scheme -- */

	function isDark() {
		return document.documentElement.classList.contains('dark');
	}

	function syncToggles() {
		var dark = isDark();

		document.querySelectorAll('.cartly-scheme-toggle').forEach(function (btn) {
			btn.setAttribute('aria-pressed', dark ? 'true' : 'false');

			if (btn.hasAttribute('aria-label')) {
				btn.setAttribute('aria-label', dark ? i18n.toLight : i18n.toDark);
			}

			var moon = btn.querySelector('.cartly-icon-moon');
			var sun = btn.querySelector('.cartly-icon-sun');
			if (moon) { moon.classList.toggle('hidden', dark); }
			if (sun) { sun.classList.toggle('hidden', !dark); }

			var label = btn.querySelector('.cartly-scheme-label');
			if (label) { label.textContent = dark ? 'Light mode' : 'Dark mode'; }
		});

		var meta = document.querySelector('meta[name="theme-color"]');
		if (meta) { meta.setAttribute('content', dark ? '#0B0C10' : '#0B0B0F'); }
	}

	function toggleScheme() {
		var next = isDark() ? 'light' : 'dark';
		document.documentElement.classList.toggle('dark', next === 'dark');
		document.documentElement.style.colorScheme = next;
		try { localStorage.setItem(SCHEME_KEY, next); } catch (e) {}
		syncToggles();
	}

	/* ------------------------------------------------------------- drawer -- */

	var drawer = document.querySelector('[data-cartly-drawer]');
	var backdrop = document.querySelector('[data-cartly-drawer-backdrop]');
	var lastFocus = null;

	function openDrawer() {
		if (!drawer) { return; }
		lastFocus = document.activeElement;
		drawer.hidden = false;
		if (backdrop) { backdrop.hidden = false; }
		drawer.classList.remove('hidden');
		drawer.classList.add('flex');
		if (backdrop) { backdrop.classList.remove('hidden'); }
		document.body.style.overflow = 'hidden';

		// Next frame, so the transition runs.
		requestAnimationFrame(function () {
			drawer.classList.remove('-translate-x-full');
		});

		document.querySelectorAll('[data-cartly-open-drawer]').forEach(function (b) {
			b.setAttribute('aria-expanded', 'true');
		});

		var focusable = drawer.querySelector('input, button, a');
		if (focusable) { focusable.focus(); }
	}

	function closeDrawer() {
		if (!drawer) { return; }
		drawer.classList.add('-translate-x-full');
		if (backdrop) { backdrop.classList.add('hidden'); backdrop.hidden = true; }
		document.body.style.overflow = '';

		document.querySelectorAll('[data-cartly-open-drawer]').forEach(function (b) {
			b.setAttribute('aria-expanded', 'false');
		});

		window.setTimeout(function () {
			drawer.classList.add('hidden');
			drawer.classList.remove('flex');
			drawer.hidden = true;
		}, 200);

		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	/* ------------------------------------------------------ filter sheet -- */

	var filters = document.querySelector('[data-cartly-filters]');

	function openFilters() {
		if (!filters) { return; }
		filters.hidden = false;
		filters.classList.remove('hidden');
		filters.classList.add('flex');
		requestAnimationFrame(function () {
			filters.classList.remove('translate-y-full');
		});
		document.body.style.overflow = 'hidden';
	}

	function closeFilters() {
		if (!filters) { return; }
		filters.classList.add('translate-y-full');
		document.body.style.overflow = '';
		window.setTimeout(function () {
			filters.classList.add('hidden');
			filters.classList.remove('flex');
			filters.hidden = true;
		}, 200);
	}

	/* --------------------------------------------------------------- wire -- */

	document.addEventListener('click', function (event) {
		var t = event.target;

		if (t.closest('.cartly-scheme-toggle')) {
			event.preventDefault();
			toggleScheme();
			return;
		}
		if (t.closest('[data-cartly-open-drawer]')) {
			event.preventDefault();
			openDrawer();
			return;
		}
		if (t.closest('[data-cartly-close-drawer]') || t.closest('[data-cartly-drawer-backdrop]')) {
			event.preventDefault();
			closeDrawer();
			return;
		}
		if (t.closest('[data-cartly-open-filters]')) {
			event.preventDefault();
			openFilters();
			return;
		}
		if (t.closest('[data-cartly-close-filters]')) {
			event.preventDefault();
			closeFilters();
			return;
		}
		if (t.closest('[data-cartly-dismiss-announcement]')) {
			var bar = document.querySelector('[data-cartly-announcement]');
			if (bar) { bar.remove(); }
			try { sessionStorage.setItem(ANNOUNCE_KEY, '1'); } catch (e) {}
		}
	});

	document.addEventListener('keydown', function (event) {
		// ⌘K / Ctrl+K focuses the header search.
		if ((event.metaKey || event.ctrlKey) && event.key && event.key.toLowerCase() === 'k') {
			var search = document.querySelector('[data-cartly-search]');
			if (search) {
				event.preventDefault();
				search.focus();
				search.select();
			}
			return;
		}

		if (event.key === 'Escape') {
			closeDrawer();
			closeFilters();
		}
	});

	// Respect a dismissal made earlier in the session.
	try {
		if (sessionStorage.getItem(ANNOUNCE_KEY) === '1') {
			var bar = document.querySelector('[data-cartly-announcement]');
			if (bar) { bar.remove(); }
		}
	} catch (e) {}

	// Follow the OS until the visitor picks a side.
	if (window.matchMedia) {
		window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
			var stored = null;
			try { stored = localStorage.getItem(SCHEME_KEY); } catch (err) {}
			if (stored) { return; }
			document.documentElement.classList.toggle('dark', e.matches);
			document.documentElement.style.colorScheme = e.matches ? 'dark' : 'light';
			syncToggles();
		});
	}

	syncToggles();
})();
