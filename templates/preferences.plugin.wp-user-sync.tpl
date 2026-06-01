<form {foreach $attributes as $attribute}
    {$attribute@key}="{$attribute}"
{/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['wp_user_sync_enabled']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['wp_user_sync_require_https']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['wp_user_sync_update_existing_by_email']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['wp_user_sync_assign_default_roles']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['wp_user_sync_external_id_field']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['wp_user_sync_default_role']}
    {include 'sys-template-parts/form.multiline.tpl' data=$elements['wp_user_sync_role_map_json']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['wp_user_sync_allowed_ips']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['wp_user_sync_api_token_hash']}
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_wp_user_sync']}

    <div class="form-alert" style="display:none;">&nbsp;</div>
</form>
{$javascript}
