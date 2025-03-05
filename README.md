Generating Data:
----------------
```php
$et = new \EventTracer("myeventlog.json");

$et->begin("Eating Cake");
[...]
$et->end();
```

If filename isn't specified, then data will be buffered
in-memory (`$et->buffer`) and can be written to disk in
one go with `$et->flush($filename)`.

Viewing Data:
-------------
Use [Perfetto](https://ui.perfetto.dev) and "Open trace file"

![Screenshot](.github/readme/trace.png)


Format Spec:
------------
[Google Doc](https://docs.google.com/document/d/1CvAClvFfyA5R-PhYUmn5OOQtYMH4h6I0nSsKchNAySU/edit)

Uses the JSON Array Format because that's the one which can be appended to from multiple threads
