<?php

namespace App\Commands;

use App\Lib\Leaf;
use App\Lib\Node;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;
use League\Csv\Reader;
use function Termwind\render;

class DecisionTreeAutomate extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'automate-resolve {data=signal.csv}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Automaticall resolve decision tree';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->task('Accesing '. $this->argument('data'), function () {
            $this->csv = Reader::createFromPath(base_path() . '/data/' . $this->argument('data'));
            $this->csv->setHeaderOffset(0);
            $this->headers = $this->csv->getHeader();
        });

        $headers = $this->csv->getHeader();

        $subjectKey = $headers[count($headers) - 1];

        $dataset = collect($this->csv->getRecords())->values();

        $subjects = $dataset->map(fn($row) => Arr::only($row, $subjectKey));

        $buildTree = function (array $paramKeys, Collection $dataset) use (&$buildTree, $subjects, $subjectKey): ?Node {
            if (count($paramKeys) === 0) {
                return null;
            }

            /* $this->getOutput()->caution('using dataset :'); */
            $this->table(array_merge($paramKeys, [$subjectKey]), $dataset);
            $this->warn('------------------------------------------------------');

            $paramEntropyScores = $this->calculateEntropyScores($dataset, $paramKeys, $subjects);

            $lowestEntropyParamKey = $paramEntropyScores->sort()->keys()->first();

            $node = new Node(
                $lowestEntropyParamKey,
                $dataset->pluck($lowestEntropyParamKey)->unique()->values()
                    ->map(fn($value) => new Leaf(
                        $value,
                        $dataset->filter(fn($data) => $data[$lowestEntropyParamKey] === $value)->values(),
                        subjectKey: $subjectKey,
                    )),
                $paramEntropyScores->toArray(),
            );

            foreach ($node->leafs as $leaf) {
                /**
                 * @var $leaf Leaf
                 */
                if ($leaf->dataset->map(fn(array $data) => $data[$subjectKey])->unique()->count() > 1) {
                    $childHeaders = $leaf->getHeaders([$node->key, $subjectKey]);
                    $leaf->setNode($buildTree(
                        $childHeaders,
                        $leaf->dataset
                            ->map(fn(array $data) => Arr::only($data, array_merge($childHeaders, [$subjectKey])))
                            ->values(),
                        $node));
                }
            }

            return $node;
        };


        $tree = $buildTree(Arr::where($headers, fn($header) => $header !== $subjectKey), $dataset);

        $this->testDataset($tree, $headers, $dataset, $subjectKey);

        return 0;
    }

 private function testDataset(Node $tree, array $headers, Collection $dataset, mixed $subjectKey): void
    {
        $dataset = $dataset->map(function (array $data) use ($tree) {
            $prediction = $tree->test($data)[0];

            $data['PREDICTION'] = $prediction;

            return $data;
        });


        render("<b class='mt-2'>Test Result : </b>");
        $this->table(array_merge($headers, ['PREDICTION']), $dataset);

        $error = $dataset->filter(fn(array $data) => $data['PREDICTION'] !== $data[$subjectKey])->count();
        $total = $dataset->count();

        $this->info("$error per $total, error rate = " . $error / $total * 100);
    }

    /**
     * @throws \Exception
     */
    private function calculateEntropyScores(Collection $dataset, array $paramKeys, Collection $subjects): Collection
    {
        $paramEntropyScores = collect();

        if ($subjects->count() === 0) {
            throw new \RuntimeException('No subjects found');
        }

        $subjectKey = array_keys($subjects->first())[0];
        $subjectValues = $subjects->groupBy($subjectKey)->keys()->sort();

        $datasetGroupBySubjectKey = $dataset->groupBy($subjectKey);

        $entropyCalculator = function ($paramKey) use ($dataset, $subjectKey, $subjectValues, $datasetGroupBySubjectKey) {
            $diffParamValues = $dataset->pluck($paramKey)->unique();
            $entropyData = collect();

            $probabilities = collect();
            foreach ($diffParamValues as $paramValue) {
                $tempSpecificParamValue = collect();

                foreach ($subjectValues as $subjectValue) {
                    $tempSpecificParamValue->push(collect([
                        $paramKey => $paramValue,
                        $subjectKey => $subjectValue,
                        'total' => $datasetGroupBySubjectKey->get($subjectValue)->where($paramKey, $paramValue)->count(),
                    ]));
                }

                // count $probability
                $total = $tempSpecificParamValue->sum('total');

                $probability = $subjectValues
                    ->map(fn($subjectValue) => $tempSpecificParamValue->firstWhere($subjectKey, $subjectValue)->get('total') / $total)
                    ->map(fn($num) => -($num) * log($num, 2))
                    ->map(fn($num) => is_nan($num) ? 0 : $num)
                    ->sum();

                $probabilities->put($paramValue, $probability);

                $entropyData = $entropyData->merge($tempSpecificParamValue);
            }


            $this->table($entropyData->first()->keys()->toArray(), $entropyData);
            $probabilities = $probabilities->each(fn($probability, $paramValue) => $this->info("$paramValue: $probability"));
            $entropy = $probabilities->map(fn($probability, $paramValue) => $entropyData->where($paramKey, $paramValue)->values()->sum('total') / $entropyData->sum('total') * $probability)->values()->sum();
            $this->info(sprintf('==> Entropy for %s,%s is %s', $paramKey, $subjectKey, $entropy));

            return $entropy;
        };

        foreach ($paramKeys as $paramKey) {
            $paramEntropyScores->put($paramKey, $entropyCalculator($paramKey));
        }

        return $paramEntropyScores;
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
