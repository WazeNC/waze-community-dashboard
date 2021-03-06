<?php

header('Content-Type: application/json; charset=utf-8');
switch($folders[2]) {
	case 'update':
		$source_id = (int)$folders[1];
		if ($source_id <= 0) {
			json_fail('No source ID or invalid source ID provided');
		}
		$stmt = $db->prepare('SELECT name, update_cooldown, notify, UNIX_TIMESTAMP(last_update) as last_update FROM dashboard_sources WHERE id = :source_id');
		execute($stmt, array(':source_id' => $source_id));
		$source = $stmt->fetch(PDO::FETCH_OBJ);
		if (!$source) {
			json_fail('Unknown source specified');
		}
		if (!file_exists('scrapers/' . $source_id . '.php')) {
			json_fail('Could not retrieve scraper logic');
		}
		if ($source->last_update > time() - $source->update_cooldown) {
			json_fail('Source was updated too recently already');
		}
		require('scrapers/' . $source_id . '.php');
		$db->beginTransaction();
		$stmt = $db->prepare("SELECT state FROM dashboard_sources WHERE id = $source_id FOR UPDATE");
		execute($stmt, array());
		$state = $stmt->fetchColumn();
		if ($state != 'inactive') {
			$db->commit();
			json_fail('Process already running or in error');
		}
		$db->query("UPDATE dashboard_sources SET state = 'running' WHERE id = $source_id");
		$db->commit();

		// change error reporting
		set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
			throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		});
		$scraper = new Scraper();
		try {
			$results = $scraper->update_source();
		} catch(Exception $e) {
			$results = array(
				'errors' => $e->getMessage()
			);
		} finally {
			$db->query("UPDATE dashboard_sources SET state = 'inactive' WHERE id = $source_id");
		}
		restore_error_handler();


		// TODO: whitelist instead of blacklist here
		unset($results['received']);
		unset($results['previous-count']);

		$results = array_filter($results); // without callback specified, array_filter removes all entries equalling to FALSE (0 == FALSE)

		if (count($results) != 0 && $source->notify && array_keys($results)[0] != 'archived') {
			$result_convert = function(&$result, $category) {
				$result = $result . ' ' . $category;
			};
			array_walk($results, $result_convert);
			send_notification(array(
				"icon_emoji" => ":waze:",
				"text" => '<http' . ($_SERVER['SERVER_PORT']==443 ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . ROOT_FOLDER . "reports|Source _'{$source->name}'_ updated>: " . implode(', ', $results) . '.'
			));
		}

		json_send(array(
			'result' => $results
		));

	default:
		json_fail('Unknown action requested');
}

?>