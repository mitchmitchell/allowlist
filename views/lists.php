<?php
$alLists = isset($lists) && is_array($lists) ? $lists : array();
?>
<div class="element-container">
	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="newlistname"><?php echo _("New List Name")?></label>
					</div>
					<div class="col-md-6">
						<input type="text" class="form-control" id="newlistname" maxlength="64" value="">
					</div>
					<div class="col-md-3">
						<button type="button" class="btn btn-default" id="listcreate"><i class="fa fa-plus"></i>&nbsp;&nbsp;<?php echo _("Add List")?></button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<table id="alListsTable" class="table table-striped">
	<thead>
		<tr>
			<th><?php echo _("ID")?></th>
			<th><?php echo _("Name")?></th>
			<th><?php echo _("Numbers")?></th>
			<th><?php echo _("Used By")?></th>
			<th><?php echo _("Actions")?></th>
		</tr>
	</thead>
	<tbody>
<?php foreach ($alLists as $alList) {
	$alId = (int)$alList['id'];
	$alName = \FreePBX\modules\Allowlist::formatListNameForDisplay($alList['name']);
	$alUses = array();
	foreach ((isset($alList['inboundRoutes']) && is_array($alList['inboundRoutes']) ? $alList['inboundRoutes'] : array()) as $alRoute) {
		$alUses[] = sprintf(_("Inbound route %s"), htmlspecialchars((string)$alRoute, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	foreach ((isset($alList['outboundRoutes']) && is_array($alList['outboundRoutes']) ? $alList['outboundRoutes'] : array()) as $alRoute) {
		$alUses[] = sprintf(_("Outbound route id %s"), htmlspecialchars((string)$alRoute, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	$alLocked = $alId === 1 || !empty($alUses);
	$alWhy = $alId === 1 ? _("The default allowlist cannot be deleted.") : (!empty($alUses) ? _("Remove this list from its routes before deleting it.") : '');
?>
		<tr>
			<td><?php echo $alId ?></td>
			<td><?php echo $alName ?></td>
			<td><?php echo (int)$alList['memberCount'] ?></td>
			<td><?php echo empty($alUses) ? _("Not assigned") : implode('<br>', $alUses) ?></td>
			<td>
				<button type="button" class="btn btn-default btn-sm" data-list-action="rename" data-list-id="<?php echo $alId ?>" data-list-name="<?php echo $alName ?>"><i class="fa fa-pencil"></i>&nbsp;<?php echo _("Rename")?></button>
				<button type="button" class="btn btn-danger btn-sm" data-list-action="delete" data-list-id="<?php echo $alId ?>" data-list-name="<?php echo $alName ?>"<?php echo $alLocked ? ' disabled title="' . htmlspecialchars($alWhy, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><i class="fa fa-trash"></i>&nbsp;<?php echo _("Delete")?></button>
			</td>
		</tr>
<?php } ?>
	</tbody>
</table>
