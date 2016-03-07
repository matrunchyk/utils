<?php


Class YpRuParser {
	const DOMAIN = 'http://www.yp.ru';
	const DELAY = 0;
	const LOG_FILE = 'system.log';
	const COOKIES_FILE = 'cookies.txt';

	protected $cookie_name;
	protected $cookie_val;

	private $urls = array();
	private $debug = false;
	private $debug_to_console = false;
	private $log_handle;
	private $emails = array();

	public function __construct(){
		$this->log_handle = fopen(__DIR__ . self::LOG_FILE, 'a');

		date_default_timezone_set('Europe/Kiev');
	}

	public function __destruct(){
		fclose($this->log_handle);
	}

	protected function fetchUrl($url, $referer = false, $ajax = false, $debug_stop = false) {
		if (!$url)
			throw new Exception('URL is not specified!');

		$ajax_str = '';
		if ($ajax) {
			$ajax_str = ' --header="X-Requested-With: XMLHttpRequest" ';
		}

		$referer_str = '';
		if ($referer) {
			$referer_str = ' --referer="' . $referer . '" ';
		}

		$tmp_file = uniqid('/tmp/', true);
		$wget = 'wget -q ' . $referer_str . ' --load-cookies ' . self::COOKIES_FILE  . ' ' . $ajax_str . ' ' . $url . ' -O ' . $tmp_file;

		exec($wget);
		$contents = file_get_contents($tmp_file);

		if ($debug_stop) {
			print $wget . PHP_EOL;
			die('Stopped. Tmp File: ' . $tmp_file);
		}

		@unlink($tmp_file);

		return $contents;
	}

	protected function secondsToTime($s) {
		$h = floor($s / 3600);
		$s -= $h * 3600;
		$m = floor($s / 60);
		$s -= $m * 60;

		return $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
	}

	public function setDebug($mode, $debug_to_console = false){
		$this->debug = $mode;
		$this->debug_to_console = $debug_to_console;

		return $this;
	}

	public function init($cookie_name, $cookie_val) {
		$this->cookie_name = $cookie_name;
		$this->cookie_val = $cookie_val;

		return $this;
	}

	public function loadUrls($file){
		$this->urls = file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);

		return $this;
	}

	public function parse() {

		$count = count($this->urls);
		$percentage = 0;
		$this->progress($percentage);
		$elapsed_start = microtime(true);
		$this->secondsCounter = array();

		foreach ($this->urls as $inc => $url) {
			$this->logme('Parsing URL: ' . $url);
			$starttime = microtime(true);
			$content = $this->fetchUrl($url);

			if (preg_match('/data-uiAjaxUrl="(\/detail\/mail[^"]+)"/', $content, $matches)) {
				$ajax_url = $matches[1];

				$this->logme('..found AJAX URL: ' . self::DOMAIN . $ajax_url);

				$respose = $this->fetchUrl(self::DOMAIN . $ajax_url, $url, true);
				$json = json_decode($respose);

				if (preg_match('/mailto:([^"]+)"/', $json->content, $matches)) {
						$this->emails[] = $matches[1];
						$this->logme('>> EMAIL: ' . $matches[1]);
				} else {
					$this->logme('Unknown ajax response: ' . $response);
				}
			}

			if (self::DELAY)
				sleep(self::DELAY);

			$percentage = ($inc + 1) * 100 / $count;
			$endtime = microtime(true);
			$this->secondsCounter[] = $endtime - $starttime;

			$average = array_sum($this->secondsCounter) / count($this->secondsCounter);
			$elapsed = microtime(true) - $elapsed_start;
			$label = 'Estimated time: ' . $this->secondsToTime($average * ($count - $inc + 1)) . "\t\tElapsed time: " . $this->secondsToTime($elapsed);
			$this->progress($percentage, $label);

		}

		if ($percentage < 100)
			$this->progress(100);

		return $this;
	}

	public function saveTo($file){

		$content = implode(PHP_EOL, $this->emails);

		file_put_contents($file, $content);

		return $this;
	}

	private function logme($message) {
		if ($this->debug) {
			$message = date('Y-m-d G:i:s') . ' - ' . print_r($message, true) . "\n";
			if ($this->debug_to_console) {
				print $message;
			} else {
				fwrite($this->log_handle, $message);
			}
		}
	}

	private function progress($percentage, $label = '') {
		$percentage_round = round($percentage);
		$percentage_frac = round($percentage, 4);

		$signs = str_repeat("#", $percentage_round);
		$spaces = str_repeat(" ", 100 - $percentage_round);

		if ($percentage == 100)
				echo ($signs . $spaces . "\t(" . $percentage_frac . "%)\t" . $label . "\n");
		else
				echo ($signs . $spaces . "\t(" . $percentage_frac . "%)\t" . $label . "\r");
	}

}

$parser = new YpRuParser;
$parser
	->setDebug(true, true)
	->loadUrls('urls.txt')
	->parse()
	->saveTo('emails.txt');
