<div id="toolbar-all">
	<button id="blkDelete" class="btn btn-danger"><?php echo _("Delete Selected")?></button>
	<a href="#" class="btn btn-default" data-toggle="modal" data-target="#addNumber"><i class="fa fa-plus"></i>&nbsp;&nbsp;<?php echo _("Allow Number")?></a>
</div>
<table id="blGrid" data-escape="true" data-toolbar="#toolbar-all" data-url="ajax.php?module=allowlist&command=getJSON&jdata=grid" data-cache="false" data-maintain-selected="true" data-show-columns="true" data-show-toggle="true" data-toggle="table" data-pagination="true" data-search="true" class="table table-striped">
    <thead>
        <tr>
            <th data-checkbox="true" data-formatter="cbFormatter"></th>
            <th data-field="number" data-sortable="true"><?php echo _("Number")?></th>
            <th data-field="description" data-formatter="descFormatter"  data-sortable="true"><?php echo _("Description")?></th>
            <th data-formatter="linkFormatter"><?php echo _("Actions")?></th>
        </tr>
    </thead>
</table>


<script type="text/javascript">
	var cbrows = [];
	function cbFormatter(val,row,i){
		cbrows[i] = row['number'];
	}

	// Grid values come from AstDB, which outside callers (*40), bulk import, restore and the API can write.
	// Escape everything that goes into the action links' HTML; ids carry only the row index.
	function alEscapeHtml(v){
		return String(v === null || v === undefined ? '' : v)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	// Descriptions are stored htmlentities-encoded; decode once so the edit dialog shows the text as before.
	function alDecodeEntities(v){
		var s = String(v === null || v === undefined ? '' : v);
		if (typeof document !== 'undefined') {
			var t = document.createElement('textarea');
			t.innerHTML = s;
			return t.value;
		}
		return s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
	}

	function linkFormatter(value,row,idx){
		var num = alEscapeHtml(row['number']);
		var desc = alEscapeHtml(alDecodeEntities(row['description']));
		var i = parseInt(idx, 10) || 0;
		var html = '<a href="#" data-toggle="modal" data-target="#addNumber" data-number="'+num+'" data-description="'+desc+'" ><i class="fa fa-pencil"></i></a>';
		html += '&nbsp;<a href="#" id="del'+i+'" data-idx="'+i+'" data-number="'+num+'" ><i class="fa fa-trash"></i></a>';
		html += '&nbsp;<a href="#" id="block'+i+'" data-idx="'+i+'" data-number="'+num+'" data-description="'+desc+'" ><i class="fa fa-ban"></i></a>';
		html += '&nbsp;<a href="#" id="report'+i+'" data-number="'+num+'"><i class="fa fa-area-chart"></i></a>';
		return html;
	}
	function descFormatter(value){
		if(value == 1){
			return "";
		}else{
			return value;
		}
	}
</script>
