{**
 * templates/messages.tpl
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The archive of messages received through the contact form.
 *
 * @uses $storageEnabled bool Whether the "Store submitted messages" setting is on
 * @uses $pluginName string Only assigned when $storageEnabled is true
 * @uses $submissions array ContactSubmission objects, newest first
 * @uses $messageTotal int Total stored for this journal
 * @uses $messagePage int Current page, 1-based
 * @uses $messageLastPage int
 * @uses $retentionDays int 0 means messages are kept indefinitely
 *}
{if !$storageEnabled}
	<p>{translate key="plugins.generic.contactForm.messages.storageDisabled"}</p>
{else}
{assign var="previousPage" value=$messagePage-1}
{assign var="nextPage" value=$messagePage+1}
{capture assign="confirmDeleteMessage"}{translate key="plugins.generic.contactForm.messages.confirmDelete"}{/capture}

<script>
	$(function() {ldelim}
		$('#contactFormMessages').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		$('#contactFormMessages').on('click', '.contact_form_delete', function() {ldelim}
			return confirm('{$confirmDeleteMessage|escape:"javascript"}');
		{rdelim});
	{rdelim});
</script>

<form class="pkp_form" id="contactFormMessages" method="post"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="messages" messagePage=$messagePage}">

	{csrf}

	<p>
		{translate key="plugins.generic.contactForm.messages.count" total=$messageTotal}
		{if $retentionDays > 0}
			{translate key="plugins.generic.contactForm.messages.retention" days=$retentionDays}
		{else}
			{translate key="plugins.generic.contactForm.messages.retention.forever"}
		{/if}
	</p>

	{if !$submissions}
		<p>{translate key="plugins.generic.contactForm.messages.none"}</p>
	{else}
		<table class="pkpTable">
			<caption class="pkp_screen_reader">{translate key="plugins.generic.contactForm.messages"}</caption>
			<thead>
				<tr>
					<th scope="col">{translate key="plugins.generic.contactForm.messages.received"}</th>
					<th scope="col">{translate key="plugins.generic.contactForm.messages.from"}</th>
					<th scope="col">{translate key="plugins.generic.contactForm.field.subject"}</th>
					<th scope="col">{translate key="plugins.generic.contactForm.messages.delivered"}</th>
					<th scope="col"><span class="pkp_screen_reader">{translate key="common.action"}</span></th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$submissions item=submission}
					<tr>
						<td>{$submission->dateSubmitted|date_format:$dateFormatShort}</td>
						<td>
							{$submission->senderName|escape}<br />
							<a href="mailto:{$submission->senderEmail|escape:"url"}">{$submission->senderEmail|escape}</a>
						</td>
						<td>
							{if $submission->subject}{$submission->subject|escape}{else}&mdash;{/if}
							<details>
								<summary>{translate key="plugins.generic.contactForm.messages.showMessage"}</summary>
								<div class="contact_form_message_body">{$submission->body|escape|nl2br}</div>
							</details>
						</td>
						<td>
							{if $submission->emailed}
								{translate key="common.yes"}
							{else}
								<strong>{translate key="common.no"}</strong>
							{/if}
						</td>
						<td>
							<button class="pkp_button contact_form_delete" type="submit"
								name="deleteSubmissionId" value="{$submission->id|escape}">
								{translate key="common.delete"}
							</button>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>

		{if $messageLastPage > 1}
			<div class="contact_form_pagination">
				{if $messagePage > 1}
					<button class="pkp_button" type="submit" name="goToPage" value="{$previousPage|escape}"
						formaction="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="messages" messagePage=$previousPage}">
						{translate key="common.pagination.previous"}
					</button>
				{/if}
				<span>{translate key="plugins.generic.contactForm.messages.page" current=$messagePage last=$messageLastPage}</span>
				{if $messagePage < $messageLastPage}
					<button class="pkp_button" type="submit" name="goToPage" value="{$nextPage|escape}"
						formaction="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="messages" messagePage=$nextPage}">
						{translate key="common.pagination.next"}
					</button>
				{/if}
			</div>
		{/if}
	{/if}
</form>
{/if}
