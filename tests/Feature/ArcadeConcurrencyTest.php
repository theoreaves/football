<?php

use Symfony\Component\Process\Process;

// These tests never switch the parent RefreshDatabase connection. Every worker boots a
// separate Laravel application against a disposable, on-disk SQLite database.
function arcadeRaceProcess(array $input): Process
{
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/arcade-concurrency-worker.php')], base_path());
    $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
    $process->setTimeout(40);

    return $process;
}

function arcadeRaceCall(array $input): array
{
    $process = arcadeRaceProcess($input);
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

function arcadeRacePair(array $fixture, array $one, array $two): array
{
    $token = bin2hex(random_bytes(6));
    $barrier = dirname($fixture['database']).'/go-'.$token;
    $processes = [];
    foreach ([$one, $two] as $index => $operation) {
        $processes[] = $process = arcadeRaceProcess($operation + $fixture + ['barrier' => $barrier, 'ready' => $barrier.'.'.$index]);
        $process->start();
    }
    try {
        $deadline = microtime(true) + 15;
        while (! is_file($barrier.'.0') || ! is_file($barrier.'.1')) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker exited before barrier: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both independent workers must reach the barrier.');
            }
            usleep(1000);
        }
        expect(file_get_contents($barrier.'.0'))->not->toBe(file_get_contents($barrier.'.1'));
        touch($barrier);

        return array_map(function (Process $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }, $processes);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}

function arcadeRaceRecording(array $session, float $lateral = 0): array
{
    $script = <<<'JS'
import {createDrill,startDrill,stepDrill} from './resources/js/arcade/simulation.js';
import {createDrillRecording} from './resources/js/arcade/recording.js';
let input=''; for await (const chunk of process.stdin) input+=chunk;
const {setup,lateral}=JSON.parse(input), state=createDrill(setup), recorder=createDrillRecording(state);
startDrill(state); recorder.capture(state);
for(let i=0;i<1200 && !state.result;i++){stepDrill(state,{x:setup.direction,z:lateral});recorder.capture(state);}
process.stdout.write(JSON.stringify(recorder.export()));
JS;
    $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->setInput(json_encode(['setup' => $session['setup'], 'lateral' => $lateral], JSON_THROW_ON_ERROR));
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->raceDirectory = sys_get_temp_dir().'/arcade-race-'.bin2hex(random_bytes(8));
    mkdir($this->raceDirectory, 0700);
    $database = $this->raceDirectory.'/test.sqlite';
    touch($database);
    $this->race = arcadeRaceCall(['database' => $database, 'action' => 'init'])['result'] + ['database' => $database];
});

afterEach(function () {
    foreach (glob($this->raceDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->raceDirectory);
});

test('independent SQLite processes reserve only one active Arcade binding', function () {
    [$one, $two] = arcadeRacePair($this->race, ['action' => 'start'], ['action' => 'start']);
    expect($one['status'])->toBe(200)->and($two['status'])->toBe(200)
        ->and($one['result']['session'])->toBe($two['result']['session']);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect($saved['sessions'])->toHaveCount(1)->and($saved['sessions'][0]['active_slot'])->toBe($this->race['game'])
        ->and($saved['history'])->toBe([])->and($saved['state']['version'])->toBe(0);
});

test('competing identical real recordings commit clock score stats and receipt exactly once', function () {
    $session = arcadeRaceCall($this->race + ['action' => 'start'])['result']['session'];
    $operation = ['action' => 'submit', 'session' => $session['envelope']['session_id'],
        'data' => ['envelope' => $session['envelope'], 'recording' => arcadeRaceRecording($session)]];
    [$one, $two] = arcadeRacePair($this->race, $operation, $operation);
    expect($one['status'])->toBe(200)->and($two['status'])->toBe(200)
        ->and($one['result']['receipt'])->toBe($two['result']['receipt']);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect($saved['history'])->toHaveCount(1)->and($saved['state']['version'])->toBe(1)
        ->and($saved['state']['stats']['home']['plays'])->toBe(1)
        ->and($saved['state'])->toBe($one['result']['state'])->toBe($two['result']['state'])
        ->and($saved['sessions'][0]['active_slot'])->toBeNull();
    $retry = arcadeRaceCall($operation + $this->race);
    expect($retry['result']['receipt'])->toBe($one['result']['receipt'])
        ->and(arcadeRaceCall($this->race + ['action' => 'inspect'])['result'])->toBe($saved);
});

test('different competing live recordings allow only one accepted result', function () {
    $session = arcadeRaceCall($this->race + ['action' => 'start'])['result']['session'];
    $operations = [];
    foreach ([0, 0.5] as $lateral) {
        $operations[] = ['action' => 'submit', 'session' => $session['envelope']['session_id'],
            'data' => ['envelope' => $session['envelope'], 'recording' => arcadeRaceRecording($session, $lateral)]];
    }
    // Validate both independently before the race: the losing submit must not merely be malformed.
    foreach ($operations as $operation) {
        expect(arcadeRaceCall(array_replace($operation, ['action' => 'validate']) + $this->race)['status'])->toBe(200);
    }
    expect($operations[0]['data'])->not->toBe($operations[1]['data']);
    $results = arcadeRacePair($this->race, ...$operations);
    expect(array_column($results, 'status'))->toContain(200, 409);
    $winner = $results[0]['status'] === 200 ? 0 : 1;
    expect(arcadeRaceCall($operations[$winner] + $this->race)['result']['receipt'])->toBe($results[$winner]['result']['receipt']);
    expect(arcadeRaceCall($operations[1 - $winner] + $this->race)['status'])->toBe(409);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect($saved['history'])->toHaveCount(1)->and($saved['state']['version'])->toBe(1);
});

test('cancellation racing a real submit leaves one terminal status and releases the slot', function () {
    $session = arcadeRaceCall($this->race + ['action' => 'start'])['result']['session'];
    $submit = ['action' => 'submit', 'session' => $session['envelope']['session_id'],
        'data' => ['envelope' => $session['envelope'], 'recording' => arcadeRaceRecording($session)]];
    [$result, $cancel] = arcadeRacePair($this->race, $submit, ['action' => 'cancel', 'session' => $submit['session']]);
    expect($cancel['status'])->toBe(200)->and($result['status'])->toBeIn([200, 409]);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    $accepted = $saved['sessions'][0]['status'] === 'accepted';
    expect($saved['sessions'][0]['status'])->toBeIn(['accepted', 'cancelled'])
        ->and($saved['sessions'][0]['active_slot'])->toBeNull()
        ->and($saved['history'])->toHaveCount($accepted ? 1 : 0)
        ->and($saved['state']['version'])->toBe($accepted ? 1 : 0)
        ->and($result['status'])->toBe($accepted ? 200 : 409);
});

test('expired slots are replaced once by competing reservations', function () {
    $session = arcadeRaceCall($this->race + ['action' => 'start'])['result']['session'];
    arcadeRaceCall($this->race + ['action' => 'expire', 'session' => $session['envelope']['session_id']]);
    [$one, $two] = arcadeRacePair($this->race, ['action' => 'start'], ['action' => 'start']);
    expect($one['status'])->toBe(200)->and($two['status'])->toBe(200)
        ->and($one['result']['session'])->toBe($two['result']['session'])
        ->and($one['result']['session']['envelope']['session_id'])->not->toBe($session['envelope']['session_id']);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect(array_column($saved['sessions'], 'status'))->toContain('expired', 'active')
        ->and(array_filter(array_column($saved['sessions'], 'active_slot')))->toHaveCount(1)
        ->and($saved['history'])->toBe([])->and($saved['state']['clock'])->toBe(180);
});

test('a concurrent serialized state mutation cannot be clobbered by an Arcade result', function () {
    $session = arcadeRaceCall($this->race + ['action' => 'start'])['result']['session'];
    $submit = ['action' => 'submit', 'session' => $session['envelope']['session_id'],
        'data' => ['envelope' => $session['envelope'], 'recording' => arcadeRaceRecording($session)]];
    [$result, $mutation] = arcadeRacePair($this->race, $submit, ['action' => 'mutate']);
    expect($mutation['status'])->toBe(200)->and($result['status'])->toBeIn([200, 409]);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect($saved['state']['race_marker'])->toBe('preserved')
        ->and($saved['state']['version'])->toBe($result['status'] === 200 ? 2 : 1)
        ->and($saved['history'])->toHaveCount($result['status'] === 200 ? 1 : 0)
        ->and($saved['sessions'][0]['active_slot'])->toBeNull();
});

function raceFlowPayload(array $fixture, array $fields): array
{
    $status = arcadeRaceCall($fixture + ['action' => 'flow_status'])['result'];

    return $fields + ['command_id' => (string) \Illuminate\Support\Str::uuid(), 'version' => $status['state']['version'], 'flow_revision' => $status['flow']['revision'], 'state_hash' => $status['flow']['state_hash']];
}

test('flow commands racing reservation cannot overwrite an active play', function () {
    $enable = raceFlowPayload($this->race, ['action' => 'preferences', 'human_side' => 'home', 'defense_mode' => 'coach']);
    expect(arcadeRaceCall($this->race + ['action' => 'flow', 'data' => $enable])['status'])->toBe(200);
    $timeout = raceFlowPayload($this->race, ['action' => 'timeout']);
    [$flow,$start] = arcadeRacePair($this->race, ['action' => 'flow', 'data' => $timeout], ['action' => 'start']);
    expect([$flow['status'], $start['status']])->toBeIn([[200, 409], [409, 200]]);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect(count($saved['history']))->toBe($flow['status'] === 200 ? 1 : 0);
});

test('identical concurrent flow commands consume timeout only once', function () {
    $enable = raceFlowPayload($this->race, ['action' => 'preferences', 'human_side' => 'home', 'defense_mode' => 'coach']);
    arcadeRaceCall($this->race + ['action' => 'flow', 'data' => $enable]);
    $timeout = raceFlowPayload($this->race, ['action' => 'timeout']);
    [$one,$two] = arcadeRacePair($this->race, ['action' => 'flow', 'data' => $timeout], ['action' => 'flow', 'data' => $timeout]);
    expect($one['status'])->toBe(200)->and($two['status'])->toBe(200);
    $saved = arcadeRaceCall($this->race + ['action' => 'inspect'])['result'];
    expect($saved['history'])->toHaveCount(1)->and($saved['state']['timeouts']['home'])->toBe(2);
});
