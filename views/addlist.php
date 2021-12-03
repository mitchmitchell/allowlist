<!--Add list Modal -->
<div class="modal fade" id="addList" tabindex="-1" role="dialog" aria-labelledby="addList" aria-hidden="true">
    <div class="modal-dialog display">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				<h4 class="modal-title" id="addList"><?php echo _("Add or replace entry") ?></h4>
			</div>
			<div class="modal-body">
				<input type="hidden" id="oldval" value=""/>
				<!--List-->
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3">
										<label class="control-label" for="list"><?php echo _("List Name")?></label>
									</div>
									<div class="col-md-9">
										<input type="text" class="form-control" id="list" name="list" value="" required>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
						</div>
					</div>
				</div>
				<!--END List-->
				<!--Description-->
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3">
										<label class="control-label" for="description"><?php echo _("Description")?></label>
									</div>
									<div class="col-md-9">
										<input type="text" class="form-control" id="description" name="description" value="">
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
						</div>
					</div>
				</div>
				<!--END Description-->
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _("Close")?></button>
				<button type="button" class="btn btn-primary" id="submitnumber"><?php echo _("Save changes")?></button>
			</div>
		</div>
	</div>
</div>
