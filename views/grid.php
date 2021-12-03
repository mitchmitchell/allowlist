<?php
$dataurl = "ajax.php?module=allowlist&command=getJSON&jdata=grid";
?>
<div id="toolbar-all">
  <a href="#" class="btn btn-default" data-toggle="modal" data-target="#addList"><i class="fa fa-plus"></i>&nbsp;&nbsp;<?php echo _("Add Allowlist")?></a>
</div>
<table id="allowlistgrid" data-url="<?php echo $dataurl?>" data-cache="false" data-toolbar="#toolbar-all" data-maintain-selected="true" data-show-columns="true" data-show-toggle="true" data-toggle="table" data-pagination="true" data-search="true" class="table table-striped">
  <thead>
    <tr>
      <th data-field="name" class="col-md-2"><?php echo _("Allowlist")?></th>
      <th data-field="date" class="col-md-1"><?php echo _("Date Created")?></th>
      <th data-field="description" class="col-md-7"><?php echo _("Description")?></th>
      <th data-field="allowlist_id" data-formatter="linkFormatter" class="col-md-2"><?php echo _("Actions")?></th>
    </tr>
  </thead>
</table>

<script type="text/javascript">
function linkFormatter(value, row, index){
  var html = '<a href="#" data-toggle="modal" data-target="#addList" data-number="'+row['number']+'" data-description="'+row['description']+'" ><i class="fa fa-pencil"></i></a>';
  html += '&nbsp;<a href="?display=allowlist&view=algrid&itemid='+value+'" ><i class="fa fa-list"></i></a>';
  html += '&nbsp;<a href="?display=allowlist&action=deleteList&itemid='+value+'" class="delAction"><i class="fa fa-trash"></i></a>';
  return html;
}
</script>
