<?php namespace Bkwld\Reporter;

// Dependencies
use Monolog\Formatter\FormatterInterface;

class Formatter implements FormatterInterface {
	
	// Private vars
	private $output = array();
	const PAD = 11;
	const WIDTH = 69;
	const WRAP = "\n           ";
	
	/**
	 * Format reporter output in the proper style.
	 */
	public function format(array $record) {
		
		// Start new log
		$this->add();
		$this->add(Style::wrap('grey', str_repeat('-', self::WIDTH)));
		$this->add(Style::wrap('grey', date('n/j/y g:i:s A')));
		$this->add();
		
		// And off formatting by type to sub functions
		$extra = $record['extra'];
		if (!empty($record['context']['request'])
			&& !empty($extra['http_method'])
			&& !empty($extra['url'])) $this->formatRequest($extra, $record['context']['request']);
		if (!empty($record['context']['command'])) $this->formatCommand($extra, $record['context']['command']);
		$this->formatTimer($extra);
		$this->formatUsage($extra);
		if (!empty($record['context']['input'])) $this->formatInput($extra, $record['context']['input']);
		if (!empty($record['context']['database'])) $this->formatDatabase($extra, $record['context']['database']);
		if (!empty($record['context']['logs'])) $this->formatLog($extra, $record['context']['logs']);
		if (!empty($record['context']['exception'])) $this->formatException($extra, $record['context']['exception']);
		
		// End
		$this->add();
		return implode("\n", $this->output);
	}
	
	/**
	 * Request info
	 */
	private function formatRequest($extra, $request) {
		$props = array();
		if ($extra['http_method'] != 'GET') $props[] = $extra['http_method'];
		if (method_exists($request, 'ajax') && $request->ajax()) $props[] = 'XHR';
		$props = count($props) ? ' ('.implode(',',$props).')' : null;
		$this->style('REQUEST', $this->wordwrap($extra['url']).$props);
	}
	
	/**
	 * CLI Command info
	 */
	private function formatCommand($extra, $command) {
		$this->style('ARTISAN', $this->wordwrap($command));
	}
	
	/**
	 * Timing of the page
	 */
	private function formatTimer($extra) {
		
		// Display execution time
		$this->style('TIME', $extra['time'].'ms');
		
		// Display custom timers
		if (!empty($extra['timers'])) {
			$this->style('TIMERS');
			$maxlen = 0;
			foreach(array_keys($extra['timers']) as $key) $maxlen = max($maxlen, strlen($key) + 4);
			foreach($extra['timers'] as $key => $val) {
				$this->add(
					Style::wrap('grey', str_pad('  '.$key.': ', $maxlen)).
					Style::wrap('cyan', $val['elapsed'].'ms')
				);
			}
		}
	}
	
	/**
	 * Memory usage of the request
	 */
	private function formatUsage($extra) {
		$this->style('MEMORY', $extra['memory_usage'].' (PEAK: '.$extra['memory_peak_usage'].')');
	}
	
	/**
	 * Request data
	 */
	private function formatInput($extra, $input) {
		$this->style('INPUT');
		$maxlen = 0;
		foreach(array_keys($input) as $key) $maxlen = max($maxlen, strlen($key) + 4);
		foreach ($input as $key => $val) {
			if (is_array($val) || is_object($val)) $val = json_encode($val);
			$this->add(
				Style::wrap('grey', str_pad('  '.$key.': ', $maxlen)).
				Style::wrap('cyan',  $this->wordwrap($val, "\n".str_repeat(' ', $maxlen)))
			);
		}
		
	}
	
	
	/**
	 * Database queries
	 */
	private function formatDatabase($extra, $queries) {
		$this->style('SQL', count($queries).' queries');
		foreach($queries as $query) {
			$sql = $query['query'];
			
			// Loop through bindings and insert into the query string
			foreach($query['bindings'] as $binding) {
				if ($binding instanceof \DateTime) $binding = $binding->format('Y-m-d H:i:s');
				elseif (is_object($binding) && !method_exists($binding, '__toString' )) $binding = 'COULD_NOT_CONVERT_TO_STRING';
				$sql = preg_replace('/\?/', "'".$binding."'", $sql, 1);
			}
			
			// Add log line
			$time = preg_replace('#[^\d.]#', '', $query['time']);
			$time = $time > 1000 ? number_format($time/1000, 2).' s' : number_format($time, 2).' ms';
			$this->add(
				Style::wrap('grey', '  ('.$time.') ').
				Style::wrap('cyan', $this->wordwrap($sql, self::WRAP, false))
			);
		}
	}
	
	/**
	 * Exceptions
	 */
	private function formatException($extra, $exception) {
		$this->add();
		$this->add(
			Style::wrap(array('bold', 'red'), str_pad('ERROR'.':', self::PAD)).
			Style::wrap('red', $this->wordwrap($exception->getMessage().
				' in '.substr($exception->getFile(), strlen(base_path())+1).
				' on line '.$exception->getLine()))
		);
	}
	
	/**
	 * Other log messages
	 */
	private function formatLog($extra, $logs) {
		$this->add();
		foreach($logs as $log) {
			
			// Diplay the message
			$color = in_array($log->level, array('error', 'critical', 'alert', 'emergency')) ? 'red' : 'yellow';
			$this->add(
				Style::wrap(array('bold', 'grey'), str_pad(strtoupper($log->level).':', self::PAD)).
				Style::wrap($color, $this->wordwrap($log->message))
			);
			
			// Show extra info
			if (!empty($log->context)) {
				$this->add(
					Style::wrap('grey', '  '.str_replace("\n", "\n  ", trim(print_r($log->context, true))))
				);
			}
		}
	}

	/**
	 * Safely wordwrap a string including converting non-strings to strings via json_encode
	 *
	 * @param mixed $msg
	 * @return string 
	 */
	private function wordwrap($msg, $break = self::WRAP, $cut = true) {
		if (!is_string($msg)) $msg = json_encode($msg);
		return wordwrap($msg, self::WIDTH, $break, $cut);
	}
	
	/**
	 * Add a line to the output
	 */
	private function add($line = '') {
		$this->output[] = $line;
	}
	
	/**
	 * Format a line and add it to the output
	 */
	private function style($label, $value='', $pad=self::PAD) {
		$this->add(
			Style::wrap(array('bold', 'grey'), str_pad($label.':', $pad)).
			Style::wrap('magenta', $value)
		);
	}
	
	
	/**
	 * Not intended to be used but required by interface
	 */
	public function formatBatch(array $records) {
		foreach ($records as $key => $record) {
			$records[$key] = $this->format($record);
		}
		return $records;
	}
	
}
