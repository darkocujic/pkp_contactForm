{**
 * templates/contactForm.tpl
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The public contact form.
 *
 * @uses $contactFormTitle string Journal-configured page title, may be empty
 * @uses $contactFormIntro string Journal-configured introduction, may be empty
 * @uses $contactFormSent bool Show the "message sent" confirmation
 * @uses $contactFormFailed bool Show the "could not be sent" warning
 * @uses $pluginPagePath string URL segment this page is served from
 * @uses $requireSubject bool Whether the subject field is mandatory
 * @uses $honeypotFieldName string Name of the decoy field
 * @uses $honeypotTimestampField string Name of the signed render-time field
 * @uses $honeypotTimestampToken string Value of the signed render-time field
 * @uses $maxLengths array Per-field character limits
 * @uses $recaptchaPublicKey string|null Site key, when reCAPTCHA is configured
 * @uses $errors array Field name => message
 * @uses $isError bool Whether validation failed
 *}
{if $contactFormTitle}
	{assign var="contactFormHeading" value=$contactFormTitle}
{else}
	{capture assign="contactFormHeading"}{translate key="plugins.generic.contactForm.page.title"}{/capture}
{/if}
{include file="frontend/components/header.tpl" pageTitleTranslated=$contactFormHeading}

<div class="page page_contact_form">
	{include file="frontend/components/breadcrumbs.tpl" currentTitle=$contactFormHeading}

	<h1>{$contactFormHeading|escape}</h1>

	{if $contactFormSent}
		<div class="cmp_notification" role="status">
			{translate key="plugins.generic.contactForm.sent"}
		</div>
	{/if}

	{if $contactFormFailed}
		<div class="cmp_notification error" role="alert">
			{translate key="plugins.generic.contactForm.error.notSent"}
		</div>
	{/if}

	{if $contactFormIntro}
		<div class="contact_form_intro">
			{$contactFormIntro|strip_unsafe_html|nl2br}
		</div>
	{/if}

	{if $isError}
		<div class="cmp_notification error" id="contactFormErrors" role="alert" tabindex="-1">
			<p>{translate key="form.errorsOccurred"}</p>
			<ul>
				{foreach from=$errors item=message}
					<li>{$message}</li>
				{/foreach}
			</ul>
		</div>
	{/if}

	<p>{translate key="common.requiredField"}</p>

	<form class="cmp_form contact_form" id="contactForm" method="post"
		action="{url page=$pluginPagePath op="submit" router=\PKP\core\PKPApplication::ROUTE_PAGE}" role="form">
		{csrf}

		<input type="hidden" name="{$honeypotTimestampField|escape}" value="{$honeypotTimestampToken|escape}" />

		{*
		 * Decoy field. Hidden from sighted users and from assistive technology,
		 * and removed from the tab order, so only an automated client fills it.
		 *}
		<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
			<label for="{$honeypotFieldName|escape}">{translate key="plugins.generic.contactForm.decoy.label"}</label>
			<input type="text" id="{$honeypotFieldName|escape}" name="{$honeypotFieldName|escape}"
				value="" tabindex="-1" autocomplete="off" />
		</div>

		<fieldset class="contact_form_fields">
			<legend class="pkp_screen_reader">{translate key="plugins.generic.contactForm.page.title"}</legend>

			<div class="fields">
				<div class="senderName{if !empty($errors.senderName)} error{/if}">
					<label for="contactFormSenderName">
						<span class="label">
							{translate key="plugins.generic.contactForm.field.name"}
							<span class="required" aria-hidden="true">*</span>
							<span class="pkp_screen_reader">{translate key="common.required"}</span>
						</span>
					</label>
					<input type="text" id="contactFormSenderName" name="senderName"
						value="{$senderName|default:''|escape}" maxlength="{$maxLengths.name}"
						autocomplete="name" required aria-required="true"
						{if !empty($errors.senderName)}aria-invalid="true" aria-describedby="contactFormSenderNameError"{/if} />
					{if !empty($errors.senderName)}
						<span class="pkp_form_error" id="contactFormSenderNameError">{$errors.senderName}</span>
					{/if}
				</div>

				<div class="senderEmail{if !empty($errors.senderEmail)} error{/if}">
					<label for="contactFormSenderEmail">
						<span class="label">
							{translate key="plugins.generic.contactForm.field.email"}
							<span class="required" aria-hidden="true">*</span>
							<span class="pkp_screen_reader">{translate key="common.required"}</span>
						</span>
					</label>
					<input type="email" id="contactFormSenderEmail" name="senderEmail"
						value="{$senderEmail|default:''|escape}" maxlength="{$maxLengths.email}"
						autocomplete="email" required aria-required="true"
						aria-describedby="contactFormSenderEmailDescription{if !empty($errors.senderEmail)} contactFormSenderEmailError{/if}"
						{if !empty($errors.senderEmail)}aria-invalid="true"{/if} />
					<span class="description" id="contactFormSenderEmailDescription">
						{translate key="plugins.generic.contactForm.field.email.description"}
					</span>
					{if !empty($errors.senderEmail)}
						<span class="pkp_form_error" id="contactFormSenderEmailError">{$errors.senderEmail}</span>
					{/if}
				</div>

				<div class="subject{if !empty($errors.subject)} error{/if}">
					<label for="contactFormSubject">
						<span class="label">
							{translate key="plugins.generic.contactForm.field.subject"}
							{if $requireSubject}
								<span class="required" aria-hidden="true">*</span>
								<span class="pkp_screen_reader">{translate key="common.required"}</span>
							{/if}
						</span>
					</label>
					<input type="text" id="contactFormSubject" name="subject"
						value="{$subject|default:''|escape}" maxlength="{$maxLengths.subject}"
						{if $requireSubject}required aria-required="true"{/if}
						{if !empty($errors.subject)}aria-invalid="true" aria-describedby="contactFormSubjectError"{/if} />
					{if !empty($errors.subject)}
						<span class="pkp_form_error" id="contactFormSubjectError">{$errors.subject}</span>
					{/if}
				</div>

				<div class="body{if !empty($errors.body)} error{/if}">
					<label for="contactFormBody">
						<span class="label">
							{translate key="plugins.generic.contactForm.field.message"}
							<span class="required" aria-hidden="true">*</span>
							<span class="pkp_screen_reader">{translate key="common.required"}</span>
						</span>
					</label>
					<textarea id="contactFormBody" name="body" rows="10" cols="60"
						maxlength="{$maxLengths.body}" required aria-required="true"
						{if !empty($errors.body)}aria-invalid="true" aria-describedby="contactFormBodyError"{/if}>{$body|default:''|escape}</textarea>
					{if !empty($errors.body)}
						<span class="pkp_form_error" id="contactFormBodyError">{$errors.body}</span>
					{/if}
				</div>
			</div>
		</fieldset>

		{if $recaptchaPublicKey}
			<fieldset class="recaptcha_wrapper">
				<legend class="pkp_screen_reader">{translate key="plugins.generic.contactForm.captcha.label"}</legend>
				<div class="fields">
					<div class="recaptcha">
						<div class="g-recaptcha" data-sitekey="{$recaptchaPublicKey|escape}"></div>
						<label for="g-recaptcha-response" style="display:none;" hidden>
							{translate key="plugins.generic.contactForm.captcha.label"}
						</label>
					</div>
				</div>
			</fieldset>
		{/if}

		<div class="buttons">
			<button class="submit" type="submit">
				{translate key="plugins.generic.contactForm.submit"}
			</button>
		</div>
	</form>
</div>

{include file="frontend/components/footer.tpl"}
