<?php
/*
 * Useful tool to add a new Transifex resource when a new core version is
 * released.
 */

require_once dirname(__FILE__) . '/includes/startup.php';

// Let's parse the script arguments
$args = parseArguments();

// Check commands
Enviro::run('msgcat', '--version');
Enviro::run('msgmerge', '--version');
Enviro::run('msgfmt', '--version');

@set_time_limit(0);

// Some initialization
require_once Enviro::mergePath(C5TTConfiguration::$includesPath, 'transifexer.php');
require_once Enviro::mergePath(C5TTConfiguration::$includesPath, 'tempfolder.php');
require_once Enviro::mergePath(C5TTConfiguration::$includesPath, 'gettext.php');
$transifexer = new Transifexer(C5TTConfiguration::$transifexHost, C5TTConfiguration::$transifexUsername, C5TTConfiguration::$transifexPassword);

// Let's get the details of the source resource
Enviro::write("Retrieving info on the source resource '{$args['source']}'... ");
$sourceInfo = $transifexer->getResourceInfo(C5TTConfiguration::$transifexCoreProject, $args['source'], true);
Enviro::write("done.\n");

// Let's check if the destination resporce exists. If it exists let's get its details
Enviro::write("Retrieving info on the destination resource '{$args['destination']}'... ");
try {
	$destinationInfo = $transifexer->getResourceInfo(C5TTConfiguration::$transifexCoreProject, $args['destination']);
}
catch(TransifexerException $x) {
	if($x->getCode() == TransifexerException::TRANSIFEX_BAD_COMMAND) {
		$destinationInfo = null;
	}
	else {
		throw $x;
	}
}
Enviro::write("done.\n");

// Let's check the pot file received from the command line arguments
if($destinationInfo) {
	if(array_key_exists('pot', $args)) {
		throw new Exception("The .pot file can't be specified, since the resource {$args['destination']} already exists.");
	}
}
else {
	if(!array_key_exists('pot', $args)) {
		throw new Exception("The .pot file must be specified, since the resource {$args['destination']} does not exist.");
	}
}

// Let's pull all the Transifex data
$transifexer->pull(C5TTConfiguration::$transifexCoreProject, C5TTConfiguration::getTransifexWorkpathCore(), true);

// Let's determine the .po files that must be copied from the source resource to the destination resource
Enviro::write("Listing translations to clone... ");
$translationsToClone = array();
$otherProjects = array();
$otherResources = array();
foreach(TransifexerTranslation::getAll(C5TTConfiguration::getTransifexWorkpathCore()) as $translation) {
	if(strcasecmp($translation->projectSlug, C5TTConfiguration::$transifexCoreProject) === 0) {
		if(strcasecmp($translation->resourceSlug, $args['source']) === 0) {
			$translationsToClone[] = $translation;
		}
		else {
			$otherResources[$translation->resourceSlug] = true;
		}
	}
	else {
		$otherProjects[$translation->projectSlug] = true;
	}
}
if(empty($translationsToClone)) {
	if(!empty($otherResources)) {
		$error = "No current translations found with the slug '{$args['source']}'.\nAvailable slugs:\n" . implode("\n", array_keys($otherResources));
	}
	elseif(!empty($otherProjects)) {
		$error = "No resources found for project '" . C5TTConfiguration::$transifexCoreProject . "'.\nAvailable projects::\n" . implode("\n", array_keys($otherProjects));
	}
	else {
		$error = 'No translations found.';
	}
	throw new Exception($error);
}
Enviro::write("done (" . count($translationsToClone) . " .po files found).\n");

Enviro::write("Verifying the translations to be cloned... ");
$moTempFolder = new TempFolder();
$moTempFile = $moTempFolder->getNewFile(true);
$numberOfErrors = 0;
foreach($translationsToClone as $translationToClone) {
	Enviro::write("\n\t" . $translationToClone->languageCode . ': ');
	try {
		Enviro::run(
			'msgfmt',
			array(
				'--check-format', // check language dependent format strings
				'--check-header', // verify presence and contents of the header entry
				'--output-file=' . escapeshellarg($moTempFile),
				escapeshellarg($translationToClone->poPath)
			)
		);
		Enviro::write('ok.');
	}
	catch(Exception $x) {
		$numberOfErrors++;
		Enviro::write("ERROR!!!\n" . $x->getMessage() . "\n", true);
	}
}
unset($moTempFolder);
if($numberOfErrors > 0) {
	Enviro::write("\nPlease fix the above $numberOfErrors . errors!\n", true);
	die(1);
}
Enviro::write("\nAll ok!\n");
if($destinationInfo) {
	// Destination resource already existing: let's retrieve its .pot file
	$tempFolder = new TempFolder();
	$tempTransifexer = new Transifexer(C5TTConfiguration::$transifexHost, C5TTConfiguration::$transifexUsername, C5TTConfiguration::$transifexPassword);
	$tempTransifexer->pull(C5TTConfiguration::$transifexCoreProject, $tempFolder->getName(), false, $args['destination'], true);
	$potFile = '';
	foreach(TransifexerTranslation::getAll($tempFolder->getName()) as $tempTranslation) {
		if(strcasecmp($tempTranslation->projectSlug, C5TTConfiguration::$transifexCoreProject) === 0) {
			if(strcasecmp($tempTranslation->resourceSlug, $args['destination']) === 0) {
				if(strcasecmp($tempTranslation->languageCode, $destinationInfo['source_language_code']) === 0) {
					$potFile = $tempTranslation->poPath;
					break;
				}
			}
		}
	}
	if(!strlen($potFile)) {
		throw new Exception("Unable to find the source .po file for the resource '{$args['destination']}' in language '{$destinationInfo['source_language_code']}'.");
	}
}
else {
	// Destination resource not existing: let's create it!
	$potFile = $args['pot'];
	Enviro::write("Creating resource '{$args['destination']}'... ");
	$options = array();
	$options['slug'] = $args['destination'];
	$options['name'] = $args['destination'];
	$options['accept_translations'] = false;
	$options['i18n_type'] = 'PO';
	if(array_key_exists('category', $sourceInfo)) {
		$options['category'] = $sourceInfo['category'];
	}
	$options['content'] = @file_get_contents($potFile);
	if($options['content'] === false) {
		throw new Exception("Unable to read content of file '$potFile'.");
	}
	$destinationInfo = $transifexer->createResource(C5TTConfiguration::$transifexCoreProject, $options);
	Enviro::write("done.\n");
	$transifexer->pull(C5TTConfiguration::$transifexCoreProject, C5TTConfiguration::getTransifexWorkpathCore(), false, $args['destination']);
	Enviro::write("done.\n");
}

// Let's determine some of the properties to be set in the header entry of the .po files of the destination resource
Enviro::write('Determining .po properties... ');
$potProperties = array();
$props = Gettext::getPoProperties($potFile);
foreach(array('Project-Id-Version', 'POT-Creation-Date') as $copy) {
	if(isset($props[$copy])) {
		$potProperties[$copy] = $props[$copy];
	}
}
Enviro::write("done.\n");

// Let's go creating/updating destination .po files!
Enviro::write("Copying " . count($translationsToClone) . " .po files... ");
$transifexDirty = false;
$poTempFolder = TempFolder::getDefault();
try {
	foreach($translationsToClone as $translationToClone) {
		$destinationPO = TransifexerTranslation::getFilePath(C5TTConfiguration::getTransifexWorkpathCore(), C5TTConfiguration::$transifexCoreProject, $args['destination'], $translationToClone->languageCode);
		if(is_file($destinationPO)) {
			// The destination .po file for this language already exists: let's update it.
			// - add to the destination .po file the translations of the source .po file (destination translations will be kept untouched)
			$mergedPO = $poTempFolder->getNewFile();
			Enviro::run('msgcat', array(
				'--use-first',
				'--force-po',
				'--no-location',
				'--no-wrap',
				'--output-file=' . escapeshellarg($mergedPO),
				escapeshellarg($destinationPO),
				escapeshellarg($translationToClone->poPath)
			));
			// - apply the destination template to the newly generated .po file (so that comments and message definitions are consistent with the .pot file)
			$finalPO = $poTempFolder->getNewFile();
			Enviro::run('msgmerge', array(
				'--no-fuzzy-matching',
				'--previous',
				'--lang=' . $translationToClone->languageCode.
				'--force-po',
				'--add-location',
				'--no-wrap',
				'--output-file=' . escapeshellarg($finalPO),
				escapeshellarg($mergedPO),
				escapeshellarg($potFile)
			));
			// - set the properties of the final .po file and place it to the final position
			$poProperties = Gettext::getPoProperties($destinationPO);
			$finalProperties = array_merge($poProperties, $potProperties);
			Gettext::setPoProperties($finalProperties, $finalPO, true, $destinationPO);
		}
		else {
			// The destination .po file does not exist.
			// - apply the destination template to the .po file from source resource (so that comments and message definitions are consistent with the .pot file)
			$finalPO = $poTempFolder->getNewFile();
			Enviro::run('msgmerge', array(
				'--no-fuzzy-matching',
				'--previous',
				'--lang=' . $translationToClone->languageCode.
				'--force-po',
				'--add-location',
				'--no-wrap',
				'--output-file=' . escapeshellarg($finalPO),
				escapeshellarg($translationToClone->poPath),
				escapeshellarg($potFile)
			));
			// -set the properties of the final .po file and place it to the final position
			Gettext::setPoProperties($potProperties, $translationToClone->poPath, true, $destinationPO);
		}
		$transifexDirty = true;
	}
	Enviro::write("done.\n");

	// Ok, all the languages have been copied: let's send the local destination files to the Transifex server
	Enviro::write("Pushing new translations for '{$args['destination']}'... ");
	$transifexer->push(C5TTConfiguration::getTransifexWorkpathCore(), C5TTConfiguration::$transifexCoreProject, $args['destination']);
	Enviro::write("done.\n");
	Enviro::write("All done: now you have to go to the Transifex website and enable translations for the new resource.\n");
}
catch(Exception $x) {
	if($transifexDirty) {
		try {
			Enviro::deleteFolder(C5TTConfiguration::getTransifexWorkpathCore(), true);
		}
		catch(Exception $x) {
		}
	}
	throw $x;
}

function parseArguments() {
	global $argv;
	$args = array();
	foreach($argv as $argi => $arg) {
		if($argi == 0) {
			continue;
		}
		$p = strpos($arg, '=');
		$name = strtolower(($p === false) ? $arg : substr($arg, 0, $p));
		$value = ($p === false) ? '' : substr($arg, $p + 1);
		switch($name) {
			case '--source':
				if(strlen($value)) {
					$args['source'] = $value;
				}
				break;
			case '--destination':
				if(strlen($value)) {
					$args['destination'] = $value;
				}
				break;
			case '--pot':
				if(strlen($value)) {
					if(!is_file($value)) {
						throw new Exception("Unable to find the file '$value'.");
					}
					$args['pot'] = realpath($value);
					if($args['pot'] === false) {
						throw new Exception("Unable to find the file '$value'.");
					}
				}
				break;
			default:
				showHelp();
				die(1);
		}
	}
	if(!(isset($args['source']) && isset($args['destination']))) {
		showHelp();
		die(1);
	}
	return $args;
}

function showHelp() {
	global $argv;
	Enviro::write(<<<EOT
Syntax: php {$argv[0]} --source=<OldResourceSlug> --destination=<NewResourceSlug> [--pot=<PathToPotFile>]
Where PathToPotFile is a local .pot file, to be specified if and only if the new resource is to be created.
EOT
	, true);
}
