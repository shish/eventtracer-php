<?php

declare(strict_types=1);

class EventTracer
{
	/** @var list<string> */
    public array $buffer;
	/** @var resource|false */
    private $fp = false;
	/** @var array<int, int> */
    private array $depths = [];

    /**
     * Create a new EventTracer object. If $filename is specified, events
     * will be written to that file in realtime. If not, they will be buffered
     * for the lifetime of the object, and they can be written to a file later
     * in one go with flush($filename)
     */
    public function __construct(?string $filename = null)
    {
        if ($filename) {
            $this->fp = fopen($filename, "a");
            // PHP SHOULD throw an exception but MAY
            // return false if fopen fails...
            assert($this->fp !== false);

            fseek($this->fp, 0, SEEK_END);
            if (ftell($this->fp) === 0) {
                fwrite($this->fp, "[\n");
            }
        } else {
            $this->buffer = [];
        }
    }

    /*
     * Meta-methods
     */

    public function clear(): void
    {
        if (!isset($this->depths[getmypid()])) {
            return;
        }

        while ($this->depths[getmypid()] > 0) {
            $this->end();
        }
    }

    public function flush(string $filename): void
    {
        if ($this->buffer == null) {
            throw new Exception("Called flush() on an unbuffered stream");
        }

        $encoded = json_encode($this->buffer);
        $this->buffer = [];

        $fp = fopen($filename, "a");
        assert($fp !== false);

        if (flock($fp, LOCK_EX)) {
            fseek($fp, 0, SEEK_END);
            if (ftell($fp) !== 0) {
                $encoded = substr($encoded, 1);
            }
            $encoded = substr($encoded, 0, strlen($encoded) - 1) . ",\n";
            fwrite($fp, $encoded);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

	/**
	 * @param array<string, mixed> $optionals
	 */
    private function log_event(string $ph, array $optionals): void
    {
        $dict = [
            "ph" => $ph,
            "ts" => microtime(true) * 1000000,
            "pid" => getmypid(),
            "tid" => getmypid(),  # php has no threads?
        ];
        foreach ($optionals as $k => $v) {
            if (!is_null($v)) {
                $dict[$k] = $v;
            }
        }
        // cname?

        if ($this->fp) {
            fwrite($this->fp, json_encode($dict) . ",\n");
        } else {
            $this->buffer[] = $dict;
        }
    }

    /*
     * Methods which map ~1:1 with the specification
     */

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function begin(string $name, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("B", ["name" => $name, "cat" => $cat, "args" => $args]);
        if (!isset($this->depths[getmypid()])) {
            $this->depths[getmypid()] = 0;
        }
        $this->depths[getmypid()]++;
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function end(?string $name = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("E", ["name" => $name, "cat" => $cat, "args" => $args]);
        $this->depths[getmypid()]--;
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function complete(float $start, float $duration, ?string $name = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("X", ["ts" => $start, "dur" => $duration, "name" => $name, "cat" => $cat, "args" => $args]);
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function instant(?string $name = null, ?string $scope = null, ?array $args = null, ?string $cat = null): void
    {
        // assert($scope in [g, p, t])
        $this->log_event("I", ["name" => $name, "cat" => $cat, "scope" => $scope, "args" => $args]);
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function counter(?string $name = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("C", ["name" => $name, "cat" => $cat, "args" => $args]);
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function async_start(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("b", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function async_instant(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("n", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function async_end(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("e", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function flow_start(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("s", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function flow_instant(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("t", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function flow_end(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("f", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args]);
    }

    // deprecated
    // public function sample(): void {P}

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function object_created(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null): void
    {
        $this->log_event("N", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function object_snapshot(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null): void
    {
        $this->log_event("O", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope]);
    }
	/**
	 * @param array<string, mixed>|null $args
	 */
    public function object_destroyed(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null): void
    {
        $this->log_event("D", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope]);
    }

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function metadata(?string $name = null, ?array $args = null): void
    {
        $this->log_event("M", ["name" => $name, "args" => $args]);
    }

    // "The precise format of the global and process arguments has not been determined yet"
    // public function memory_dump_global(): void {V}
    // public function memory_dump_process(): void {v}

	/**
	 * @param array<string, mixed>|null $args
	 */
    public function mark(?string $name = null, ?array $args = null, ?string $cat = null): void
    {
        $this->log_event("R", ["name" => $name, "cat" => $cat, "args" => $args]);
    }

    public function clock_sync(?string $name = null, ?string $sync_id = null, ?float $issue_ts = null): void
    {
        $this->log_event("c", ["name" => $name, "args" => ["sync_id" => $sync_id, "issue_ts" => $issue_ts]]);
    }

    public function context_enter(?string $name = null, ?string $id = null): void
    {
        $this->log_event("(", ["name" => $name, "id" => $id]);
    }
    public function context_leave(?string $name = null, ?string $id = null): void
    {
        $this->log_event(")", ["name" => $name, "id" => $id]);
    }
}
