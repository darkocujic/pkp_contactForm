{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Contact Form plugin settings.
 *
 * @uses $pluginName string
 * @uses $honeypotFieldName string The decoy field's current name
 * @uses $captchaConfigured bool Whether reCAPTCHA is set up in config.inc.php
 * @uses $contactFormUrl string Full URL of the public page
 * @uses $settingDefaults array Shipped defaults, shown as placeholders
 *}
<script>
	$(function() {ldelim}
		$('#contactFormSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="contactFormSettingsForm" method="post"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">

	{csrf}
	{include file="common/formErrors.tpl"}

	{fbvFormArea id="contactFormPageArea" title="plugins.generic.contactForm.settings.page"}
		{fbvFormSection}
			<p class="pkp_help">
				{translate key="plugins.generic.contactForm.settings.page.description" url=$contactFormUrl|escape}
			</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="pagePath" value=$pagePath required=true
				label="plugins.generic.contactForm.settings.pagePath"
				size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" multilingual=true id="pageTitle" value=$pageTitle
				label="plugins.generic.contactForm.settings.pageTitle"}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="textarea" multilingual=true id="introText" value=$introText
				label="plugins.generic.contactForm.settings.introText" rows=4}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="contactFormRecipientArea" title="plugins.generic.contactForm.settings.recipient"}
		{fbvFormSection}
			{fbvElement type="text" id="recipientEmail" value=$recipientEmail
				label="plugins.generic.contactForm.settings.recipientEmail"
				size=$fbvStyles.size.LARGE}
			{fbvElement type="text" id="recipientName" value=$recipientName
				label="plugins.generic.contactForm.settings.recipientName"
				size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="textarea" id="ccEmails" value=$ccEmails rows=2
				label="plugins.generic.contactForm.settings.ccEmails"}
		{/fbvFormSection}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="requireSubject" checked=$requireSubject
				label="plugins.generic.contactForm.settings.requireSubject"}
			{fbvElement type="checkbox" id="enableAcknowledgement" checked=$enableAcknowledgement
				label="plugins.generic.contactForm.settings.enableAcknowledgement"}
		{/fbvFormSection}
		{fbvFormSection}
			<p class="pkp_help">
				{translate key="plugins.generic.contactForm.settings.enableAcknowledgement.warning"}
			</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="contactFormSpamArea" title="plugins.generic.contactForm.settings.spam"}
		{fbvFormSection}
			<p class="pkp_help">
				{if $captchaConfigured}
					{translate key="plugins.generic.contactForm.settings.captcha.on"}
				{else}
					{translate key="plugins.generic.contactForm.settings.captcha.off"}
				{/if}
			</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="honeypotMinSeconds" value=$honeypotMinSeconds
				label="plugins.generic.contactForm.settings.honeypotMinSeconds"
				size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="honeypotMaxSeconds" value=$honeypotMaxSeconds
				label="plugins.generic.contactForm.settings.honeypotMaxSeconds"
				size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="regenerateHoneypotField" checked=false
				label="plugins.generic.contactForm.settings.regenerateHoneypotField"}
		{/fbvFormSection}
		{fbvFormSection}
			<p class="pkp_help">
				{translate key="plugins.generic.contactForm.settings.honeypotFieldName" name=$honeypotFieldName|escape}
			</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="maxUrls" value=$maxUrls
				label="plugins.generic.contactForm.settings.maxUrls"
				size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxPerHourPerIp" value=$maxPerHourPerIp
				label="plugins.generic.contactForm.settings.maxPerHourPerIp"
				size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxPerHourPerContext" value=$maxPerHourPerContext
				label="plugins.generic.contactForm.settings.maxPerHourPerContext"
				size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="textarea" id="blocklist" value=$blocklist rows=4
				label="plugins.generic.contactForm.settings.blocklist"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="contactFormRetentionArea" title="plugins.generic.contactForm.settings.retention"}
		{fbvFormSection}
			{fbvElement type="text" id="retentionDays" value=$retentionDays
				label="plugins.generic.contactForm.settings.retentionDays"
				size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{fbvFormSection}
			<p class="pkp_help">
				{translate key="plugins.generic.contactForm.settings.retentionDays.description"}
			</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}

	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
