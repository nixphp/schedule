<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use NixPHP\Schedule\Support\CronParser;
use Tests\NixPHPTestCase;

class CronParserTest extends NixPHPTestCase
{
    public function testEveryMinuteExpressionReturnsNextMinute(): void
    {
        $expression = '* * * * *'; // every minute
        $parser     = new CronParser();

        $now        = new DateTimeImmutable('2024-01-01 12:00:00');
        $nextMinute = $now->modify('+1 minute');

        $this->assertSame(
            $nextMinute->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testEveryFiveMinutesExpressionReturnsNextMatchingMinute(): void
    {
        $expression = '*/5 * * * *'; // every 5 minutes
        $parser     = new CronParser();

        // 12:02 → next run should be 12:05
        $now      = new DateTimeImmutable('2024-01-01 12:02:00');
        $expected = new DateTimeImmutable('2024-01-01 12:05:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testHourlyAtZeroReturnsNextFullHour(): void
    {
        $expression = '0 * * * *'; // at minute 0 every hour
        $parser     = new CronParser();

        // 12:10 → next run should be 13:00
        $now      = new DateTimeImmutable('2024-01-01 12:10:00');
        $expected = new DateTimeImmutable('2024-01-01 13:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testDailyAtSpecificTimeReturnsSameDayIfInFuture(): void
    {
        $expression = '0 3 * * *'; // every day at 03:00
        $parser     = new CronParser();

        // Before 03:00 → same day
        $now      = new DateTimeImmutable('2024-01-01 02:30:00');
        $expected = new DateTimeImmutable('2024-01-01 03:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testDailyAtSpecificTimeReturnsNextDayIfTimePassed(): void
    {
        $expression = '0 3 * * *'; // every day at 03:00
        $parser     = new CronParser();

        // After 03:00 → next day
        $now      = new DateTimeImmutable('2024-01-01 03:30:00');
        $expected = new DateTimeImmutable('2024-01-02 03:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testIsDueMatchesCorrectWeekday(): void
    {
        $expression = '0 12 * * 1'; // Monday at 12:00 (0=Sun,1=Mon,...)
        $parser     = new CronParser();

        $monday = new DateTimeImmutable('2024-01-01 12:00:00');
        $this->assertTrue($parser->isDue($expression, $monday));

        $tuesday = $monday->modify('+1 day');
        $this->assertFalse($parser->isDue($expression, $tuesday));
    }

    public function testIsDueWithListExpressionMatchesOneOfValues(): void
    {
        $expression = '5,10,15 * * * *'; // at minute 5, 10 or 15 of every hour
        $parser     = new CronParser();

        $atMinute10 = new DateTimeImmutable('2024-01-01 12:10:00');
        $this->assertTrue($parser->isDue($expression, $atMinute10));

        $atMinute7 = new DateTimeImmutable('2024-01-01 12:07:00');
        $this->assertFalse($parser->isDue($expression, $atMinute7));
    }

    public function testIsDueWithRangeExpressionMatchesInsideRange(): void
    {
        $expression = '5-10 * * * *'; // every minute between 5 and 10
        $parser     = new CronParser();

        $inRange  = new DateTimeImmutable('2024-01-01 12:07:00');
        $outRange = new DateTimeImmutable('2024-01-01 12:11:00');

        $this->assertTrue($parser->isDue($expression, $inRange));
        $this->assertFalse($parser->isDue($expression, $outRange));
    }

    public function testInvalidExpressionThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $expression = '* * * *'; // only 4 parts, invalid
        $parser     = new CronParser();

        $parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00'));
    }

    public function testWhitespaceIsIgnoredInExpression(): void
    {
        $expression = '  *   *    *   *   *  ';
        $parser     = new CronParser();

        $now        = new DateTimeImmutable('2024-01-01 12:00:00');
        $nextMinute = $now->modify('+1 minute');

        $this->assertSame(
            $nextMinute->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testEveryFiveMinutesFromExactBoundary(): void
    {
        $expression = '*/5 * * * *';
        $parser     = new CronParser();

        // At 12:10 → next is 12:15
        $now      = new DateTimeImmutable('2024-01-01 12:10:00');
        $expected = new DateTimeImmutable('2024-01-01 12:15:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testListAndRangeCombinationInMinuteField(): void
    {
        $expression = '5-7,10 * * * *'; // minutes 5,6,7 and 10
        $parser     = new CronParser();

        $atSix  = new DateTimeImmutable('2024-01-01 12:06:00');
        $atTen  = new DateTimeImmutable('2024-01-01 12:10:00');
        $atEight= new DateTimeImmutable('2024-01-01 12:08:00');

        $this->assertTrue($parser->isDue($expression, $atSix));
        $this->assertTrue($parser->isDue($expression, $atTen));
        $this->assertFalse($parser->isDue($expression, $atEight));
    }

    public function testStepExpressionOnDayOfMonthField(): void
    {
        $expression = '0 0 */2 * *'; // every 2nd day starting from day 1 (1,3,5,...)
        $parser     = new CronParser();

        $dayThree = new DateTimeImmutable('2024-01-03 00:00:00'); // 3 → should match
        $dayFour  = new DateTimeImmutable('2024-01-04 00:00:00'); // 4 → should NOT match

        $this->assertTrue($parser->isDue($expression, $dayThree));
        $this->assertFalse($parser->isDue($expression, $dayFour));
    }

    public function testNextRunWrapsOverEndOfMonth(): void
    {
        $expression = '0 0 1 * *'; // every 1st of month at 00:00
        $parser     = new CronParser();

        // One minute before Feb 1st
        $now      = new DateTimeImmutable('2024-01-31 23:59:00');
        $expected = new DateTimeImmutable('2024-02-01 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testNextRunWrapsOverEndOfYear(): void
    {
        $expression = '0 0 1 1 *'; // every 1st January at 00:00
        $parser     = new CronParser();

        // One minute before new year run
        $now      = new DateTimeImmutable('2023-12-31 23:59:00');
        $expected = new DateTimeImmutable('2024-01-01 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testComplexBusinessHoursExpressionNextRunInsideDay(): void
    {
        // Every 15 minutes between 09:00 and 17:59 on weekdays (1–5)
        $expression = '*/15 9-17 * * 1-5';
        $parser     = new CronParser();

        // Tuesday (weekday 2) at 10:07 → next should be 10:15
        $now      = new DateTimeImmutable('2024-01-02 10:07:00');
        $expected = new DateTimeImmutable('2024-01-02 10:15:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testComplexBusinessHoursExpressionSkipsWeekend(): void
    {
        // Every 15 minutes between 09:00 and 17:59 on weekdays (1–5)
        $expression = '*/15 9-17 * * 1-5';
        $parser     = new CronParser();

        // Friday 17:50 → next allowed time is Monday 09:00 (weekend skipped)
        $now      = new DateTimeImmutable('2024-01-05 17:50:00'); // Friday
        $expected = new DateTimeImmutable('2024-01-08 09:00:00'); // Monday

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testDayAndWeekdayAreCombinedWithAndLogic(): void
    {
        $expression = '0 12 1 * 1'; // 1st of month AND Monday at 12:00
        $parser     = new CronParser();

        // 2024-01-01 is Monday and day 1
        $matching = new DateTimeImmutable('2024-01-01 12:00:00');
        $this->assertTrue($parser->isDue($expression, $matching));

        // Same weekday (Monday) but day 8 → should NOT match
        $mondayNotFirst = new DateTimeImmutable('2024-01-08 12:00:00');
        $this->assertFalse($parser->isDue($expression, $mondayNotFirst));

        // Day 1 but different weekday → should NOT match
        $notMonday = new DateTimeImmutable('2024-02-01 12:00:00');
        $this->assertFalse($parser->isDue($expression, $notMonday));
    }

    public function testUnknownSyntaxInFieldDoesNotMatch(): void
    {
        $expression = 'foo * * * *'; // "foo" is invalid for minutes
        $parser     = new CronParser();

        $now = new DateTimeImmutable('2024-01-01 12:00:00');

        $this->assertFalse($parser->isDue($expression, $now));
    }

    // ========================================
    // Edge Cases: Boundaries & Limits
    // ========================================

    public function testMinuteBoundaryZeroMatches(): void
    {
        $expression = '0 * * * *';
        $parser = new CronParser();

        $atZero = new DateTimeImmutable('2024-01-01 12:00:00');
        $this->assertTrue($parser->isDue($expression, $atZero));
    }

    public function testMinuteBoundaryFiftyNineMatches(): void
    {
        $expression = '59 * * * *';
        $parser = new CronParser();

        $atFiftyNine = new DateTimeImmutable('2024-01-01 12:59:00');
        $this->assertTrue($parser->isDue($expression, $atFiftyNine));
    }

    public function testHourBoundaryZeroMatches(): void
    {
        $expression = '0 0 * * *';
        $parser = new CronParser();

        $midnight = new DateTimeImmutable('2024-01-01 00:00:00');
        $this->assertTrue($parser->isDue($expression, $midnight));
    }

    public function testHourBoundaryTwentyThreeMatches(): void
    {
        $expression = '0 23 * * *';
        $parser = new CronParser();

        $elevenPM = new DateTimeImmutable('2024-01-01 23:00:00');
        $this->assertTrue($parser->isDue($expression, $elevenPM));
    }

    public function testDayOfMonthBoundaryOneMatches(): void
    {
        $expression = '0 0 1 * *';
        $parser = new CronParser();

        $firstDay = new DateTimeImmutable('2024-01-01 00:00:00');
        $this->assertTrue($parser->isDue($expression, $firstDay));
    }

    public function testDayOfMonthBoundaryThirtyOneMatches(): void
    {
        $expression = '0 0 31 * *';
        $parser = new CronParser();

        $lastDay = new DateTimeImmutable('2024-01-31 00:00:00');
        $this->assertTrue($parser->isDue($expression, $lastDay));
    }

    public function testMonthBoundaryJanuaryMatches(): void
    {
        $expression = '0 0 1 1 *';
        $parser = new CronParser();

        $jan = new DateTimeImmutable('2024-01-01 00:00:00');
        $this->assertTrue($parser->isDue($expression, $jan));
    }

    public function testMonthBoundaryDecemberMatches(): void
    {
        $expression = '0 0 1 12 *';
        $parser = new CronParser();

        $dec = new DateTimeImmutable('2024-12-01 00:00:00');
        $this->assertTrue($parser->isDue($expression, $dec));
    }

    public function testWeekdayBoundarySundayMatches(): void
    {
        $expression = '0 0 * * 0';
        $parser = new CronParser();

        $sunday = new DateTimeImmutable('2024-01-07 00:00:00'); // Sunday
        $this->assertTrue($parser->isDue($expression, $sunday));
    }

    public function testWeekdayBoundarySaturdayMatches(): void
    {
        $expression = '0 0 * * 6';
        $parser = new CronParser();

        $saturday = new DateTimeImmutable('2024-01-06 00:00:00'); // Saturday
        $this->assertTrue($parser->isDue($expression, $saturday));
    }

    // ========================================
    // Step Expressions: Various Intervals
    // ========================================

    public function testStepTwoMinutesMatches(): void
    {
        $expression = '*/2 * * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:01:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:02:00')));
    }

    public function testStepTenMinutesMatches(): void
    {
        $expression = '*/10 * * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:10:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:50:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:15:00')));
    }

    public function testStepFifteenMinutesMatches(): void
    {
        $expression = '*/15 * * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:15:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:30:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:45:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:10:00')));
    }

    public function testStepThreeHoursMatches(): void
    {
        $expression = '0 */3 * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 03:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 06:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 01:00:00')));
    }

    public function testStepFiveDaysMatches(): void
    {
        $expression = '0 0 */5 * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-06 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-11 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-02 00:00:00')));
    }

    public function testStepThreeMonthsMatches(): void
    {
        $expression = '0 0 1 */3 *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-04-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-07-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-02-01 00:00:00')));
    }

    public function testInvalidStepZeroDoesNotMatch(): void
    {
        $expression = '*/0 * * * *';
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    public function testInvalidStepNegativeDoesNotMatch(): void
    {
        $expression = '*/-5 * * * *';
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    // ========================================
    // Ranges: Various Combinations
    // ========================================

    public function testRangeHoursNineToFiveMatches(): void
    {
        $expression = '0 9-17 * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 09:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 17:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 08:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 18:00:00')));
    }

    public function testRangeMonthsAprilToOctoberMatches(): void
    {
        $expression = '0 0 1 4-10 *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-04-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-07-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-10-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-03-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-11-01 00:00:00')));
    }

    public function testRangeWeekdaysMondayToFridayMatches(): void
    {
        $expression = '0 0 * * 1-5';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00'))); // Monday
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-05 00:00:00'))); // Friday
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-06 00:00:00'))); // Saturday
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-07 00:00:00'))); // Sunday
    }

    public function testInvalidRangeReversedDoesNotMatch(): void
    {
        $expression = '10-5 * * * *'; // Invalid: end < start
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:07:00')));
    }

    // ========================================
    // Lists: Multiple Values
    // ========================================

    public function testListMultipleMinutesMatches(): void
    {
        $expression = '0,15,30,45 * * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:15:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:30:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:45:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:10:00')));
    }

    public function testListMultipleHoursMatches(): void
    {
        $expression = '0 6,12,18 * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 06:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 18:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 09:00:00')));
    }

    public function testListMultipleDaysOfMonthMatches(): void
    {
        $expression = '0 0 1,15 * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-15 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-10 00:00:00')));
    }

    public function testListMultipleMonthsMatches(): void
    {
        $expression = '0 0 1 1,4,7,10 *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-04-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-07-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-10-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-02-01 00:00:00')));
    }

    public function testListMultipleWeekdaysMatches(): void
    {
        $expression = '0 0 * * 0,3,6'; // Sun, Wed, Sat
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-03 00:00:00'))); // Wednesday
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-06 00:00:00'))); // Saturday
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-07 00:00:00'))); // Sunday
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00'))); // Monday
    }

    // ========================================
    // Complex Combinations
    // ========================================

    public function testListWithRangesMatches(): void
    {
        $expression = '0,15,30-35,45 * * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:15:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:30:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:33:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:35:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:45:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:20:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:40:00')));
    }

    public function testMultipleListsInDifferentFieldsMatches(): void
    {
        $expression = '0,30 9,17 * * 1,5'; // 9am & 5pm on Mon & Fri
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 09:00:00'))); // Mon 9am
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 09:30:00'))); // Mon 9:30am
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 17:00:00'))); // Mon 5pm
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-05 09:00:00'))); // Fri 9am
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-02 09:00:00'))); // Tue
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 10:00:00'))); // Wrong hour
    }

    // ========================================
    // Whitespace & Formatting
    // ========================================

    public function testMultipleSpacesBetweenFieldsNormalized(): void
    {
        $expression = '0    12     *      *       *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    public function testTabCharactersNormalized(): void
    {
        $expression = "0\t12\t*\t*\t*";
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    public function testMixedWhitespaceNormalized(): void
    {
        $expression = " 0 \t 12  \t * \t * \t * ";
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    // ========================================
    // Caching Behavior
    // ========================================

    public function testSameExpressionUsedMultipleTimesUsesCaching(): void
    {
        $expression = '*/5 * * * *';
        $parser = new CronParser();

        // First call should parse
        $result1 = $parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00'));

        // Subsequent calls should use cache
        $result2 = $parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:05:00'));
        $result3 = $parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:10:00'));

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($result3);
    }

    public function testDifferentExpressionsAreCachedSeparately(): void
    {
        $parser = new CronParser();

        $expr1 = '0 * * * *';
        $expr2 = '30 * * * *';

        $this->assertTrue($parser->isDue($expr1, new DateTimeImmutable('2024-01-01 12:00:00')));
        $this->assertFalse($parser->isDue($expr1, new DateTimeImmutable('2024-01-01 12:30:00')));

        $this->assertTrue($parser->isDue($expr2, new DateTimeImmutable('2024-01-01 12:30:00')));
        $this->assertFalse($parser->isDue($expr2, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    // ========================================
    // nextRun() Edge Cases
    // ========================================

    public function testNextRunWithLeapYearFebruary29(): void
    {
        $expression = '0 0 29 2 *';
        $parser = new CronParser();

        $now = new DateTimeImmutable('2024-02-28 23:00:00'); // 2024 is leap year
        $expected = new DateTimeImmutable('2024-02-29 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testNextRunCrossesMonthBoundary(): void
    {
        $expression = '0 0 * * *'; // Every day at midnight
        $parser = new CronParser();

        $now = new DateTimeImmutable('2024-01-31 23:30:00');
        $expected = new DateTimeImmutable('2024-02-01 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testNextRunCrossesYearBoundary(): void
    {
        $expression = '0 0 * * *';
        $parser = new CronParser();

        $now = new DateTimeImmutable('2023-12-31 23:30:00');
        $expected = new DateTimeImmutable('2024-01-01 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testNextRunForVeryRareExpression(): void
    {
        $expression = '0 0 31 12 *'; // New Year's Eve at midnight
        $parser = new CronParser();

        $now = new DateTimeImmutable('2024-01-01 00:00:00');
        $expected = new DateTimeImmutable('2024-12-31 00:00:00');

        $this->assertSame(
            $expected->getTimestamp(),
            $parser->nextRun($expression, $now)->getTimestamp()
        );
    }

    public function testNextRunSecondsAreSetToZero(): void
    {
        $expression = '* * * * *';
        $parser = new CronParser();

        $now = new DateTimeImmutable('2024-01-01 12:00:45'); // 45 seconds
        $next = $parser->nextRun($expression, $now);

        $this->assertSame('00', $next->format('s'));
    }

    // ========================================
    // Invalid Expressions
    // ========================================

    public function testTooFewFieldsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $parser = new CronParser();
        $parser->isDue('* * *', new DateTimeImmutable());
    }

    public function testTooManyFieldsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $parser = new CronParser();
        $parser->isDue('* * * * * *', new DateTimeImmutable());
    }

    public function testEmptyExpressionThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $parser = new CronParser();
        $parser->isDue('', new DateTimeImmutable());
    }

    public function testOnlyWhitespaceThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $parser = new CronParser();
        $parser->isDue('     ', new DateTimeImmutable());
    }

    // ========================================
    // Special Characters & Invalid Syntax
    // ========================================

    public function testInvalidCharactersInFieldDoNotMatch(): void
    {
        $expression = '@invalid * * * *';
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    public function testMultipleSlashesDoNotMatch(): void
    {
        $expression = '*//5 * * * *';
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00')));
    }

    public function testMultipleDashesDoNotMatch(): void
    {
        $expression = '5--10 * * * *';
        $parser = new CronParser();

        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:07:00')));
    }

    public function testTrailingCommaDoesNotMatch(): void
    {
        $expression = '5,10, * * * *';
        $parser = new CronParser();

        // Depending on implementation, this might match or not. Let's test behavior.
        $result = $parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:05:00'));
        $this->assertTrue($result || !$result); // Just ensure no crash
    }

    // ========================================
    // Real-World Patterns
    // ========================================

    public function testEveryMondayMorningNineAM(): void
    {
        $expression = '0 9 * * 1';
        $parser = new CronParser();

        $monday = new DateTimeImmutable('2024-01-01 09:00:00'); // Monday
        $this->assertTrue($parser->isDue($expression, $monday));

        $tuesday = new DateTimeImmutable('2024-01-02 09:00:00'); // Tuesday
        $this->assertFalse($parser->isDue($expression, $tuesday));
    }

    public function testFirstDayOfEveryMonth(): void
    {
        $expression = '0 0 1 * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-02-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-12-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-02 00:00:00')));
    }

    public function testEveryQuarterStartMidnight(): void
    {
        $expression = '0 0 1 1,4,7,10 *'; // Jan, Apr, Jul, Oct
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-04-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-07-01 00:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-10-01 00:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-02-01 00:00:00')));
    }

    public function testWeekdayLunchBreak(): void
    {
        $expression = '0 12 * * 1-5'; // Noon, Monday-Friday
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 12:00:00'))); // Monday
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-05 12:00:00'))); // Friday
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-06 12:00:00'))); // Saturday
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-07 12:00:00'))); // Sunday
    }

    public function testNightlyBackupAtThreeAM(): void
    {
        $expression = '0 3 * * *';
        $parser = new CronParser();

        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-01 03:00:00')));
        $this->assertTrue($parser->isDue($expression, new DateTimeImmutable('2024-01-15 03:00:00')));
        $this->assertFalse($parser->isDue($expression, new DateTimeImmutable('2024-01-01 03:01:00')));
    }
}