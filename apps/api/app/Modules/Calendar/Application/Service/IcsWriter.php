<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Service;

use Carbon\CarbonImmutable;

/**
 * RFC 5545 output, written by hand and kept small.
 *
 * A dependency was considered and rejected: reading an RRULE is a decade of
 * edge cases and belongs in a library (docs/03 §4), but WRITING a VEVENT is
 * four fields and two rules — escaping and line folding — both of which are
 * here and both of which are tested. The asymmetry is the point.
 *
 * The two rules, because they are the whole reason naive ICS output breaks in
 * real clients:
 *
 * 1. **Escaping.** A comma or semicolon in a title ends the property value.
 *    "Fix pricing, then deploy" silently becomes a truncated event in Outlook.
 * 2. **Folding.** Lines longer than 75 octets must be split with CRLF + a
 *    space. Clients that receive a 300-character SUMMARY drop the event.
 */
final class IcsWriter
{
    private const CRLF = "\r\n";

    /** RFC 5545 §3.1: 75 octets, not characters. */
    private const MAX_OCTETS = 75;

    /**
     * @param  list<array{uid: string, summary: string, starts_at: CarbonImmutable, all_day: bool, description?: string, url?: string}>  $events
     */
    public function render(string $calendarName, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Enterprise Work OS//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            // Clients poll on their own schedule; this is a hint, not a
            // promise, and 1 hour matches how often work dates realistically
            // move.
            'X-PUBLISHED-TTL:PT1H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($events as $event) {
            $lines = [...$lines, ...$this->event($event)];
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map($this->fold(...), $lines)).self::CRLF;
    }

    /**
     * @param  array{uid: string, summary: string, starts_at: CarbonImmutable, all_day: bool, description?: string, url?: string}  $event
     * @return list<string>
     */
    private function event(array $event): array
    {
        $starts = $event['starts_at'];

        $lines = [
            'BEGIN:VEVENT',
            // Stable across regenerations: a client that re-fetches must update
            // the event it already has rather than add a second copy.
            'UID:'.$event['uid'],
            'DTSTAMP:'.CarbonImmutable::now()->utc()->format('Ymd\THis\Z'),
        ];

        if ($event['all_day']) {
            // A date, not a moment. DTSTART;VALUE=DATE keeps a milestone on its
            // day in every timezone; a UTC timestamp moves it for half the
            // world.
            $lines[] = 'DTSTART;VALUE=DATE:'.$starts->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$starts->addDay()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$starts->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$starts->utc()->addMinutes(30)->format('Ymd\THis\Z');
        }

        $lines[] = 'SUMMARY:'.$this->escape($event['summary']);

        if (($event['description'] ?? '') !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape((string) $event['description']);
        }

        if (($event['url'] ?? '') !== '') {
            $lines[] = 'URL:'.$this->escape((string) $event['url']);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** RFC 5545 §3.3.11: backslash, semicolon, comma, and newline. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /** RFC 5545 §3.1: fold at 75 octets, continuation lines start with a space. */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_OCTETS) {
            return $line;
        }

        $folded = mb_strcut($line, 0, self::MAX_OCTETS);
        $rest = substr($line, strlen($folded));

        while ($rest !== '') {
            // 74, because the leading space counts toward the octet limit.
            $chunk = mb_strcut($rest, 0, self::MAX_OCTETS - 1);
            $folded .= self::CRLF.' '.$chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $folded;
    }
}
