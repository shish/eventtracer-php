<?php

declare(strict_types=1);

class EventTracer
{
    /** @var list<array<string, mixed>> */
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
        if (!isset($this->buffer)) {
            throw new Exception("Called flush() on an unbuffered stream");
        }

        $encoded = json_encode($this->buffer);
        if ($encoded === false) {
            throw new Exception("Failed to encode buffer");
        }
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
    * @param array<string, mixed> $raw
     */
    public function begin(string $name, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("B", ["name" => $name, "cat" => $cat, "args" => $args, ...$raw]);
        if (!isset($this->depths[getmypid()])) {
            $this->depths[getmypid()] = 0;
        }
        $this->depths[getmypid()]++;
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function end(?string $name = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("E", ["name" => $name, "cat" => $cat, "args" => $args, ...$raw]);
        $this->depths[getmypid()]--;
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function complete(float $start, float $duration, ?string $name = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("X", ["ts" => $start, "dur" => $duration, "name" => $name, "cat" => $cat, "args" => $args, ...$raw]);
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function instant(?string $name = null, ?string $scope = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        // assert($scope in [g, p, t])
        $this->log_event("I", ["name" => $name, "cat" => $cat, "scope" => $scope, "args" => $args, ...$raw]);
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function counter(?string $name = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("C", ["name" => $name, "cat" => $cat, "args" => $args, ...$raw]);
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function async_start(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("b", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function async_instant(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("n", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function async_end(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("e", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function flow_start(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("s", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function flow_instant(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("t", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function flow_end(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("f", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, ...$raw]);
    }

    // deprecated
    // public function sample(): void {P}

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function object_created(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null, array $raw = []): void
    {
        $this->log_event("N", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function object_snapshot(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null, array $raw = []): void
    {
        $this->log_event("O", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope, ...$raw]);
    }
    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function object_destroyed(?string $name = null, ?string $id = null, ?array $args = null, ?string $cat = null, ?string $scope = null, array $raw = []): void
    {
        $this->log_event("D", ["name" => $name, "id" => $id, "cat" => $cat, "args" => $args, "scope" => $scope, ...$raw]);
    }

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function metadata(?string $name = null, ?array $args = null, array $raw = []): void
    {
        $this->log_event("M", ["name" => $name, "args" => $args, ...$raw]);
    }

    // "The precise format of the global and process arguments has not been determined yet"
    // public function memory_dump_global(): void {V}
    // public function memory_dump_process(): void {v}

    /**
     * @param array<string, mixed>|null $args
     * @param array<string, mixed> $raw
     */
    public function mark(?string $name = null, ?array $args = null, ?string $cat = null, array $raw = []): void
    {
        $this->log_event("R", ["name" => $name, "cat" => $cat, "args" => $args, ...$raw]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function clock_sync(?string $name = null, ?string $sync_id = null, ?float $issue_ts = null, array $raw = []): void
    {
        $this->log_event("c", ["name" => $name, "args" => ["sync_id" => $sync_id, "issue_ts" => $issue_ts, ...$raw]]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function context_enter(?string $name = null, ?string $id = null, array $raw = []): void
    {
        $this->log_event("(", ["name" => $name, "id" => $id, ...$raw]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function context_leave(?string $name = null, ?string $id = null, array $raw = []): void
    {
        $this->log_event(")", ["name" => $name, "id" => $id, ...$raw]);
    }
}
