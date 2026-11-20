<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use OneOakDigital\CommonStats\Jobs\FlushHourStats;
use OneOakDigital\CommonStats\Jobs\FlushMinuteStats;

class ScheduleTest extends TestCase
{
    public function test_the_jobs_are_scheduled_every_minute_and_hourly(): void
    {
        $events = $this->scheduledJobs();

        $this->assertSame('* * * * *', $events[FlushMinuteStats::class]->expression);
        $this->assertSame('1 * * * *', $events[FlushHourStats::class]->expression);

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    public function test_nothing_is_scheduled_when_turned_off(): void
    {
        config(['common-stats.schedule' => false]);

        $this->assertSame([], $this->scheduledJobs());
    }

    /**
     * @return array<string, Event>
     */
    private function scheduledJobs(): array
    {
        $this->app->forgetInstance(Schedule::class);

        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => in_array($event->description, [FlushMinuteStats::class, FlushHourStats::class], true))
            ->keyBy('description')
            ->all();
    }
}
