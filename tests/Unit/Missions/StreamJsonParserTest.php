<?php

use App\Support\Missions\StreamJsonParser;

test('a json object split across two chunks is still one event', function () {
    $parser = new StreamJsonParser;

    // Le CLI écrit vite ; le tuyau coupe où il veut, y compris au milieu d'une
    // clé. Un événement perdu là, c'est une étape invisible à l'écran.
    expect($parser->feed('{"type":"assist'))->toBe([])
        ->and($parser->feed('ant","seq":1}'."\n"))->toBe([['type' => 'assistant', 'seq' => 1]]);
});

test('several events in one chunk come out in order', function () {
    $parser = new StreamJsonParser;

    $events = $parser->feed('{"type":"a"}'."\n".'{"type":"b"}'."\n".'{"type":"c"}'."\n");

    expect(array_column($events, 'type'))->toBe(['a', 'b', 'c']);
});

test('a line that is not json is dropped without taking the rest with it', function () {
    $parser = new StreamJsonParser;

    $events = $parser->feed("Debug: something\n".'{"type":"ok"}'."\n".'{"broken'."\n");

    expect($events)->toBe([['type' => 'ok']]);
});

test('a last line with no trailing newline is recovered on flush', function () {
    $parser = new StreamJsonParser;

    expect($parser->feed('{"type":"result"}'))->toBe([])
        ->and($parser->flush())->toBe([['type' => 'result']])
        ->and($parser->flush())->toBe([]);
});

test('a top-level value that is not an object is ignored', function () {
    $parser = new StreamJsonParser;

    expect($parser->feed("42\n\"hello\"\nnull\n"))->toBe([]);
});
