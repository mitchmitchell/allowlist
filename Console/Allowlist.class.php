<?php
//Namespace should be FreePBX\Console\Command
namespace FreePBX\Console\Command;

//Symfony stuff for all the various things this command does
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

//Tables for displaying list items
use Symfony\Component\Console\Helper\Table;

//Process
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\HelpCommand;

class Allowlist extends Command {
	protected function configure() {
		$this->setName('allowlist')
			->setDescription(_('Allowlist Module'))
			->setDefinition(array(
				new InputOption('add', 'a', InputOption::VALUE_REQUIRED, _('Add a new number to the allow list')),
				new InputOption('delete', 'd', InputOption::VALUE_REQUIRED, _('Delete a number from the allow list')),
				new InputOption('destination', 't', InputOption::VALUE_NONE, _('Destination for non-allowlisted callers')),
				new InputOption('list', 'l', InputOption::VALUE_NONE, _('List all allowlist entries')),
				new InputOption('did', 'i', InputOption::VALUE_NONE, _('Set whether allowlist is processed for inbound did')),
				new InputOption('route', 'o', InputOption::VALUE_NONE, _('Set whether autoadd to the allowlist is processed for outbound route')),
				new InputOption('settings', 's', InputOption::VALUE_NONE, _('Enable/Disable settings for allow list processing')),
				new InputOption('import', 'm', InputOption::VALUE_REQUIRED, _('Import settings from file')),
				new InputOption('export', 'x', InputOption::VALUE_REQUIRED, _('Export settings to file'))
			));
	}
	protected function execute(InputInterface $input, OutputInterface $output){
		$allowlist = \FreePBX::create()->Allowlist;
		if($input->getOption('list')) {
			$table = new Table($output);
			$table->setHeaders(array(_('ID'),_('Name')));
			$rows = array();
			$entries = $allowlist->getAllowlist();
			foreach($entries as $entry) {
				$rows[] = array(
					$entry['number'],
					$entry['description']
				);
			}
			$table->setRows($rows);
			$table->render();
			$this->displaySettings($allowlist, $output)->render();
			$this->displayDestination($allowlist, $output)->render();
			$this->displayDIDs($allowlist, $output)->render();
			$this->displayRoutes($allowlist, $output)->render();
		}
		if ($input->getOption('export')){
			$filename = $input->getOption('export');
			$entries = $allowlist->getAllowlist();
			$listcsv = $this->formatCsvList($entries);
			$fs = new Filesystem();
			try {
				$fs->dumpFile($filename,$listcsv);
				return true;
			} catch (IOExceptionInterface $e){
				$output->writeln(sprintf(_("Could not write to %s"),$filename));
				return false;
			}
		}
		if ($input->getOption('import')){
			$filename = $input->getOption('import');
			$handle = fopen($filename, 'r+b');
 			while (($data = fgetcsv($handle)) !== false) {
				if ($allowlist->numberAdd(array( "number" => $data[0], "description" => $data[1]))) {
					$output->writeln("<question>added number: ". $data[0] ."</question>");
				} else {
					$output->writeln("<error>could not add number: ". $data[0] ."</error>");
				}
			}
			fclose($handle);
		}
		if($input->getOption('add')) {
			$number = $input->getOption('add');
			$io = new SymfonyStyle($input, $output);
			$description = $io->ask('description for the number');
			if ($allowlist->numberAdd(array( "number" => $number, "description" => $description))) {
				$output->writeln("<question>added number: ". $number ."</question>");
			} else {
				$output->writeln("<error>could not add number: ". $number ."</error>");
			}
		}
		if($input->getOption('route')) {
			$routes = $this->listOutgoingRoutes($allowlist);
			$routeids = array();
			foreach($routes as $route){
				$routeids[$route['route_id']] =  $route['route_id'];
			}

			$table = new Table($output);
			$table->setHeaders(array('ID',_('Name'),_('Auto')));
			$table->setRows($routes);
			$output->writeln(_('Choose an Outbound Route to enable/disable'));
			$helper = $this->getHelper('question');
			$question = new ChoiceQuestion($table->render(),$routeids,0);
			$id = $helper->ask($input, $output, $question); // $id is one based so that zero appears as invalid answer (0 = carriage return)
			if($routes[($id - 1)]['checked'] == 'Yes'){
				$output->writeln(sprintf(_('Disabling Outbound Route %s'),$routes[($id - 1)]['name']));
				$allowlist->routeDelete($id);
			} else if($routes[($id - 1)]['checked'] == 'No'){
				$output->writeln(sprintf(_('Enabling Outbound Route %s'),$routes[($id - 1)]['name']));
				$allowlist->routeAdd($id);
			}
			$routes = $this->listOutgoingRoutes($allowlist);
			$table = new Table($output);
			$table->setHeaders(array('ID',_('Name'),_('Auto')));
			$table->setRows($routes);
			$table->render();
		}
		if($input->getOption('did')) {
			$routes = $this->listIncomingRoutes($allowlist);
			$routeids = array();
			foreach($routes as $route){
				$routeids[$route['routeid']] =  $route['routeid'];
			}

			$table = new Table($output);
			$table->setHeaders(array('ID',_('DID'),_('CID'),_('Destination'), _('Description'),_('Checked')));
			$table->setRows($routes);
			$output->writeln(_('Choose a DID/CID to enable/disable'));
			$helper = $this->getHelper('question');
			$question = new ChoiceQuestion($table->render(),$routeids,-1);
			$id = $helper->ask($input, $output, $question); // $id is one based so that zero appears as invalid answer (0 = carriage return)
			if($routes[($id - 1)]['checked'] == 'Yes'){
				$output->writeln(sprintf(_('Disabling DID %s'),$routes[($id - 1)]['description']));
				$allowlist->didDelete($routes[($id - 1)]['extension'], $routes[($id - 1)]['cidnum']);
			} else if($routes[($id - 1)]['checked'] == 'No'){
				$output->writeln(sprintf(_('Enabling DID %s'),$routes[($id - 1)]['description']));
				$allowlist->didAdd($routes[($id - 1)]['extension'], $routes[($id - 1)]['cidnum']);
			}
			$routes = $this->listIncomingRoutes($allowlist);
			$table = new Table($output);
			$table->setHeaders(array('ID',_('DID'),_('CID'),_('Destination'), _('Description'),_('Checked')));
			$table->setRows($routes);
			$table->render();
		}

		if($input->getOption('settings')) {
			$optionids = array(1 => 'allow', 2 => 'pause');
			$output->writeln(_('Choose a setting to enable/disable'));
			$helper = $this->getHelper('question');
			$question = new ChoiceQuestion($this->displaySettings($allowlist, $output)->render(),$optionids,-1);
			$id = $helper->ask($input, $output, $question); // $id is one based so that zero appears as invalid answer (0 = carriage return)
			$this->toggleOptions($allowlist,$id);
			$output->writeln("<question>toggling setting option: ". $id ."</question>");
			$this->displaySettings($allowlist, $output)->render();;
		}

		if($input->getOption('destination')) {
			$none = $input->getOption('destination');
			$destinations = $this->getDestinations();
			ksort($destinations, SORT_STRING);
			$modnames = $modids = array();
			$count = 0;
			$modids[$count] =  $count;
			$modnames[$count] =  array($count, "None");
			foreach($destinations as $mod => $dest){
				$modids[$count + 1] =  $count + 1;
				$modnames[$count + 1] =  array($count + 1, $mod);
				$count++;
			}
			$table = new Table($output);
			$table->setHeaders(array('ID',_('Module')));
			$table->setRows($modnames);
			$output->writeln(_('Choose a Module for Destinations'));
			$helper = $this->getHelper('question');
			$question = new ChoiceQuestion($table->render(),$modids,-1);
			$id = $helper->ask($input, $output, $question); // $id is one based so that zero will mean delete the destination
			if ($id != "0") {
				$destlist = $destinations[$modnames[$id][1]];
				$destmodname = $modnames[$id][1];
				ksort($destlist, SORT_STRING);
				// now build the second level menu to select the actual destination
				$destnames = $destids = array();
				$count = 0;
				foreach($destlist as $name => $dest){
					$destids[$count + 1] =  $count + 1;
					$destnames[$count + 1] =  array($count + 1, $name, $dest);
					$count++;
				}
				$table = new Table($output);
				$table->setHeaders(array('ID',_('Name'),_('Destination')));
				$table->setRows($destnames);
				$output->writeln(_('Choose a Destination'));
				$helper = $this->getHelper('question');
				$question = new ChoiceQuestion($table->render(),$destids,-1);
				$id = $helper->ask($input, $output, $question); // $id is one based so that zero appears as invalid answer (0 = carriage return)
				$output->writeln("<question>Setting allowlist destination to ". $destmodname . "->" . $destnames[$id][1] ." = " . $destnames[$id][2] ."</question>");
				$allowlist->destinationSet($destnames[$id][2]);
			} else {
				$output->writeln("<question>Setting allowlist destination to None.</question>");
				$allowlist->astman->database_del('allowlist', 'dest');
			}
		}

		if($input->getOption('delete')) {
			$number = $input->getOption('delete');
			if ($allowlist->numberDel($number)) {
				$output->writeln("<question>deleted number: ". $number ."</question>");
			} else {
				$output->writeln("<error>could not delete number: ". $number ."</error>");
			}
		}
		if(!$input->getOption('add') && !$input->getOption('delete') && !$input->getOption('list') && !$input->getOption('destination') && !$input->getOption('settings') && !$input->getOption('route')  && !$input->getOption('did') && !$input->getOption('import')  && !$input->getOption('export')) {
			$this->outputHelp($input,$output);
			exit(4);
		}
	}

	private function addEntry($entry,$output) {
		$allowlist = \FreePBX::create()->Allowlist;
		$output->writeln("Add is not yet avaiable for '".$entry['number']."' coming soon!");
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 * @throws \Symfony\Component\Console\Exception\ExceptionInterface
	 */
	protected function outputHelp(InputInterface $input, OutputInterface $output)	 {
		$help = new HelpCommand();
		$help->setCommand($this);
		return $help->run($input, $output);
	}


	private function formatCsvList($allowdata)
	{
		$handle = fopen('php://memory', 'r+b');
		//fputcsv($handle, ['number', 'description']);
		foreach ($allowdata as $source => $target) {
			fputcsv($handle, [$target['number'], $target['description']]);
		}

		rewind($handle);
		$output = stream_get_contents($handle);
		fclose($handle);

		return $output;
	}

	private function displaySettings($allowlist, $output)
	{
		$table = new Table($output);
		$table->setHeaders(array(_('Setting'),_('Value')));
		$rows = array();
		$rows[] = array(
			'allow cm/phonebook known callers',
			$allowlist->allowknowncallersGet() == 0 ? 'No' : 'Yes'
		);
		$rows[] = array(
			'pause allowlist processing',
			$allowlist->pauseGet() == 0 ? 'No' : 'Yes'
		);
		$table->setRows($rows);
		return $table;
	}	

	private function displayDestination($allowlist, $output)
	{
		$table = new Table($output);
		$table->setHeaders(array(_('Option'),_('Value')));
		$rows = array();
		$rows[] = array(
			'destination for non allowed callers',
			$allowlist->destinationGet()
		);
		$table->setRows($rows);
		return $table;
	}	


	private function displayDIDs($allowlist, $output)
	{
		$dids = $this->listIncomingRoutes($allowlist);
		$table = new Table($output);
		$table->setHeaders(array('ID',_('DID'),_('CID'),_('Destination'), _('Description'),_('Checked')));
		$table->setRows($dids);
		return $table;
	}	


	private function displayRoutes($allowlist, $output)
	{
		$routes = $this->listOutgoingRoutes($allowlist);
		$table = new Table($output);
		$table->setHeaders(array('ID',_('Name'),_('Auto')));
		$table->setRows($routes);
		return $table;
	}	

	private function listIncomingRoutes($allowlist){
		$db = \FreePBX::Database();
		//
		// this version of MariaDB does not support the ROW_NUMBER function so we have to fake it to have routeids in the record
		//
		$sql = "SELECT 0 AS `routeid`, `extension` , `cidnum` , `destination` , `description` , 0 AS `checked` FROM `incoming` ORDER BY `extension`";
		$ob = $db->query($sql,\PDO::FETCH_ASSOC);
		if($ob->rowCount()){
			$gotRows = $ob->fetchAll();
		}
		// fill in the fake row numbers as route ids
		foreach($gotRows as $id => $r){
			$gotRows[$id]['routeid'] = $id + 1; // add one so that menu displays see carriage return (returns 0) as an invalid selection
			$gotRows[$id]['checked'] = $allowlist->didIsSet($gotRows[$id]['extension'], $gotRows[$id]['cidnum']) == 1 ? "Yes" : "No";
		}

		return $gotRows;
	}

	private function listOutgoingRoutes($allowlist){
		$db = \FreePBX::Database();
		//
		// this version of MariaDB does not support the ROW_NUMBER function so we have to fake it to have routeids in the record
		//
		$sql = "SELECT `route_id` , `name` , 0 AS `checked` FROM `outbound_routes` ORDER BY `route_id`";
		$ob = $db->query($sql,\PDO::FETCH_ASSOC);
		if($ob->rowCount()){
			$gotRows = $ob->fetchAll();
		}
		// fill in the autoadd status 
		foreach($gotRows as $id => $r){
			$gotRows[$id]['checked'] = $allowlist->routeIsSet($gotRows[$id]['route_id']) == 1 ? "Yes" : "No";
		}
		return $gotRows;
	}

	private function toggleOptions($allowlist,$option) {
		switch($option) {
		case 'pause':
			$allowlist->pauseSet( !$allowlist->pauseGet() );
			break;
		case 'allow':
			$allowlist->allowknowncallersSet( !$allowlist->allowknowncallersGet() );
			break;
		default:
			return false;
		}
		return true;
	}

	private function getDestinations() {
		$results = \FreePBX::Destinations()->getAllDestinations();
		foreach ($results as $destination) {
			$list[$destination['name']][$destination['description']] = $destination['destination'];
		}
		ksort($list, SORT_STRING);
		return $list;
	}
}
