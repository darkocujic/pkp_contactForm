/**
 * @file cypress/tests/functional/ContactForm.cy.js
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Integration tests for the Contact Form plugin.
 *
 * These run inside an OJS tree, not from this repository on its own: they rely
 * on PKP's Cypress support commands (cy.login, cy.logout) and on the test data
 * the OJS test suite installs. Copy the plugin into
 * plugins/generic/contactForm, then from the OJS root:
 *
 *   npx cypress run --spec plugins/generic/contactForm/cypress/tests/functional/ContactForm.cy.js
 */

describe('Contact Form plugin', function () {
	const journal = 'publicknowledge';
	const pagePath = 'contactForm';

	it('can be enabled by a journal manager', function () {
		cy.login('dbarnes', null, journal);
		cy.visit(`/index.php/${journal}/management/settings/website`);
		cy.get('button[id="plugins-button"]').click();
		cy.get('input[id^="select-cell-contactformplugin-enabled"]').check();
		cy.get('div:contains(\'The plugin "Contact Form" has been enabled.\')');
		cy.logout();
	});

	it('serves the public page with the anti-spam machinery in place', function () {
		cy.visit(`/index.php/${journal}/${pagePath}`);

		cy.get('form#contactForm').should('exist');
		// Every submission is bound to a session and to a signed render time.
		cy.get('form#contactForm input[name="csrfToken"]').should('exist');
		cy.get('form#contactForm input[name="cfRenderedAt"]')
			.should('have.attr', 'value')
			.and('match', /^[0-9]+\.[0-9a-f]{64}$/);

		// The decoy field is present but hidden from sighted users and from
		// assistive technology.
		cy.get('form#contactForm div[aria-hidden="true"] input[type="text"]')
			.should('exist')
			.should('not.be.visible')
			.should('have.attr', 'tabindex', '-1');
	});

	it('reports missing fields individually', function () {
		cy.visit(`/index.php/${journal}/${pagePath}`);

		// Bypass the browser's own required-field handling so the server-side
		// validation is what gets exercised.
		cy.get('form#contactForm').invoke('attr', 'novalidate', 'novalidate');
		cy.get('form#contactForm button[type="submit"]').click();

		cy.get('#contactFormErrors').should('exist');
		cy.get('#contactFormSenderNameError').should('exist');
		cy.get('#contactFormSenderEmailError').should('exist');
	});

	it('rejects a filled decoy field with the generic message', function () {
		cy.visit(`/index.php/${journal}/${pagePath}`);

		cy.get('#contactFormSenderName').type('Spam Bot', {force: true});
		cy.get('#contactFormSenderEmail').type('bot@example.org', {force: true});
		cy.get('#contactFormSubject').type('Cheap everything', {force: true});
		cy.get('#contactFormBody').type('Please visit my site.', {force: true});
		cy.get('form#contactForm div[aria-hidden="true"] input[type="text"]')
			.type('http://spam.example', {force: true});

		cy.get('form#contactForm button[type="submit"]').click();

		cy.get('#contactFormErrors').should('exist');
		// The visitor must not learn which check fired.
		cy.get('#contactFormErrors').should('not.contain', 'honeypot');
		cy.get('#contactFormSenderNameError').should('not.exist');
	});

	it('accepts a genuine message and archives it', function () {
		const subject = `Cypress test ${Date.now()}`;

		cy.visit(`/index.php/${journal}/${pagePath}`);

		cy.get('#contactFormSenderName').type('Ada Lovelace');
		cy.get('#contactFormSenderEmail').type('ada@example.org');
		cy.get('#contactFormSubject').type(subject);
		cy.get('#contactFormBody').type('Could you clarify the word limit for review articles?');

		// The minimum time-to-submit defaults to three seconds.
		cy.wait(4000);
		cy.get('form#contactForm button[type="submit"]').click();

		cy.get('div[role="status"]').should('exist');
		cy.url().should('include', 'sent=1');

		cy.login('dbarnes', null, journal);
		cy.visit(`/index.php/${journal}/management/settings/website`);
		cy.get('button[id="plugins-button"]').click();
		cy.get('tr[id*="contactformplugin"] a:contains("Messages")').click();
		cy.get('#contactFormMessages').should('contain', subject);
		cy.logout();
	});
});
