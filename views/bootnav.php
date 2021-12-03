<?php
$dataurl = "ajax.php?module=allowlist&command=getJSON&jdata=grid";
?>
<div id="toolbar-rnav">
  <a href='?display=allowlist&view=form' class="btn btn-default"><i class="fa fa-plus"></i> <?php echo _("Add Allowlist")?></a>
  <a href='?display=allowlist' class="btn btn-default"><i class="fa fa-list"></i> <?php echo _("Allowlist List")?></a>
</div>
<table id="allowlistgridrnav"
      data-url="<?php echo $dataurl?>"
      data-cache="false"
      data-toolbar="#toolbar-rnav"
      data-toggle="table"
      data-search="true"
      class="table">
  <thead>
    <tr>
      <th data-field="name" class="col-md-2"><?php echo _("Allowlist")?></th>
      <th data-field="date" class="col-md-1"><?php echo _("Date Created")?></th>
      <th data-field="description" class="col-md-3"><?php echo _("Description")?></th>
    </tr>
  </thead>
</table>

<script type="text/javascript">
	$("#allowlistgridrnav").on('click-row.bs.table',function(e,row,elem){
		window.location = '?display=allowlist&view=algrid&itemid='+row['allowlist_id'];
	})
</script>
