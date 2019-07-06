<?php
declare(strict_types=1);

class EventTracer {
	public $buffer;
	private $fp;
	private $depths = [];

	/**
	 * Create a new EventTracer object. If $filename is specified, events
	 * will be written to that file in realtime. If not, they will be buffered
	 * for the lifetime of the object, and they can be written to a file later
	 * in one go with flush($filename)
	 */
	public function __construct(?string $filename=null) {
		if($filename) {
			$this->fp = fopen($filename, "a");
			fseek($this->fp, 0, SEEK_END);
			if(ftell($this->fp) === 0) {
				fwrite($this->fp, "[\n");
			}
		}
		else {
			$this->buffer = [];
		}
	}

	public function clear() {
		if(!isset($this->depths[posix_getpid()])) return;

		while($this->depths[posix_getpid()] > 0) {
			$this->end();
		}
	}

	public function flush(string $filename) {
		if($this->buffer == null) {
			throw new Exception("Called flush() on an unbuffered stream");
		}

		$encoded = json_encode($this->buffer);
		$this->buffer = [];

		$fp = fopen($filename, "a");
		if(flock($fp, LOCK_EX)) {
			fseek($fp, 0, SEEK_END);
			if(ftell($fp) !== 0) {
				$encoded = substr($encoded, 1);
			}
			$encoded = substr($encoded, 0, strlen($encoded)-1) . ",\n";
			fwrite($fp, $encoded);
			fflush($fp);
			flock($fp, LOCK_UN);
		}
		fclose($fp);
	}

	private function log_event(string $ph, array $optionals): void {
		$dict = [
			"ph" => $ph,
			"ts" => microtime(true) * 1000000,
			"pid" => posix_getpid(),
			"tid" => posix_getpid(),  # php has no threads?
		];
		foreach($optionals as $k => $v) {
			if(!is_null($v)) {
				$dict[$k] = $v;
			}
		}
		// cname?

		if($this->fp) {
			fwrite($this->fp, json_encode($dict) . ",\n");
		}
		else {
			$this->buffer[] = $dict;
		}
	}

	public function begin(string $name, ?string $cat=null, ?array $args=null): void {
		$this->log_event("B", ["name"=>$name, "cat"=>$cat, "args"=>$args]);
		if(!isset($this->depths[posix_getpid()])) $this->depths[posix_getpid()] = 0;
		$this->depths[posix_getpid()]++;
	}

	public function end(?string $name=null, ?string $cat=null, ?array $args=null): void {
		$this->log_event("E", ["name"=>$name, "cat"=>$cat, "args"=>$args]);
		$this->depths[posix_getpid()]--;
	}

	public function complete(int $duration, string $name=null, ?string $cat=null, ?array $args=null): void {
		$this->log_event("X", ["dur"=>$duration, "name"=>$name, "cat"=>$cat, "args"=>$args]);
	}

	/*
	public function instant(?string $name=null, ?string $cat=null, ?array $args=null): void {
		$this->log_event("i", ["name"=>$name, "cat"=>$cat, "args"=>$args]);
	}
	*/

	/*
	public function counter(): void {}

	public function async_start(): void {}
	public function async_instant(): void {}
	public function async_end(): void {}

	public function flow_start(): void {}
	public function flow_step(): void {}
	public function flow_end(): void {}

	public function sample(): void {}

	public function object_created(): void {}
	public function object_snapshot(): void {}
	public function object_destroyed(): void {}

	public function metadata(): void {}

	public function memory_dump_global(): void {}
	public function memory_dump_process(): void {}

	public function mark(): void {}

	public function clock_sync(): void {}

	public function context(): void {}
	*/
}
