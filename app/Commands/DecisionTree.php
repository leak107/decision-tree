<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;
use League\Csv\Reader as Reader;

class DecisionTree extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'resolve {data=signal.csv}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Program to resolve signal using decision tree';

    protected Reader $csv;

    protected ?Collection $dataset;

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

        $this->datasetPreset();
        $firstIterativeData = $this->calculateEntropy($this->dataset);

        dd($firstIterativeData->sortBy('PARENT_ENTROPY')->toArray());
    }

    protected function datasetPreset()
    {
        $rawData = $this->csv->getRecords();

        $this->dataset = collect();

        foreach ($rawData as $data) {
            $status = $data['STATUS'];

            collect($data)->each(function ($value, $key) use ($status) {
                if ($key !== 'STATUS') {
                    if ($this->dataset->has($key)) {
                        if ($this->dataset[$key]->has($value)) {
                            if ($this->dataset[$key][$value]->has($status)) {
                                $this->dataset[$key][$value][$status] = $this->dataset[$key][$value][$status] += 1;
                            } else {
                                $this->dataset[$key][$value][$status] = 1;
                            }
                        } else {
                            $this->dataset[$key][$value] = collect([
                                $status => 1
                            ]);
                        }
                    } else {
                        $this->dataset->put($key, collect([
                            $value => collect([
                                $status => 1,
                            ])
                        ]));
                    }

                }
            });
        }
    }

    protected function calculateEntropy($data)
    {
        $data->each(function ($data) {
            $totalData = $data->flatten()->sum();

            $data->transform(function ($value) use ($totalData) {
                $childTotal = $value->flatten()->sum();
                $first = $value->first() / $childTotal * log($value->first() / $childTotal, 2);
                $last = $value->last() / $childTotal * log($value->last() / $childTotal, 2);
                $value['CHILD_ENTROPY'] = abs($first + $last) * ($childTotal / $totalData);
                $value['TOTAL'] = $childTotal;

                return $value;
            });

            $data['PARENT_ENTROPY'] = $data->sum('CHILD_ENTROPY');
        });

        return $data;
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
