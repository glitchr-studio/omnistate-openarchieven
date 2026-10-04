<?php

namespace Omnistate\OpenArchieven;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Archive;
use Omnistate\Model\CivilPerson;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecord;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Model\Place;
use Omnistate\Model\Sex;
use Omnistate\Registry\CivilRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open Archives (openarchieven.nl): the indexes of some two hundred Dutch
 * and Belgian archives in one search - civil registration (births,
 * marriages, deaths), church books (baptisms, burials), population
 * registers, notarial deeds - plus what they index of Suriname and of
 * France (INSEE's deaths). A record names everyone in the act, says which
 * archive keeps the original and under which reference, and often links the
 * scan of the act.
 *
 * Free, no key, open data; four calls a second per address, kept here.
 * The API answers its errors in the body, with HTTP 200. Its country filter
 * is the country of the archive that holds the record, and knows four
 * values: nl, be, fr, sr.
 */
final class OpenArchieven implements CivilRegistryInterface
{
    public const URL = 'https://api.openarchieven.nl/1.1';

    /** The event types Open Archives filters on, in Dutch whatever the language asked. */
    private const EVENTS = [
        'Geboorte' => CivilRecordKind::BIRTH,
        'Doop' => CivilRecordKind::BAPTISM,
        'Huwelijk' => CivilRecordKind::MARRIAGE,
        'Overlijden' => CivilRecordKind::DEATH,
        'Begraven' => CivilRecordKind::BURIAL,
    ];

    /** Seconds between two calls: four a second at most. */
    private const PACE = 0.26;

    private static float $last = 0.0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly float $timeout = 10,
        /** The language of the labels (archive, source type, role): en, nl, de, fr. */
        private readonly string $lang = 'en',
        private readonly string $url = self::URL,
    ) {
    }

    public function name(): string
    {
        return 'openarchieven';
    }

    /** The values its country filter knows; any other is ignored by the API, hence not declared. */
    public function countries(): array
    {
        return ['NL', 'BE', 'FR', 'SR'];
    }

    public function kinds(): array
    {
        return [CivilRecordKind::BIRTH, CivilRecordKind::BAPTISM, CivilRecordKind::MARRIAGE, CivilRecordKind::DEATH, CivilRecordKind::BURIAL, CivilRecordKind::OTHER];
    }

    public function period(): ?Period
    {
        return null;
    }

    public function hasImages(): bool
    {
        return true;
    }

    public function supports(CivilQuery $query): bool
    {
        return $query->hasName() && $query->inCountries(...$this->countries());
    }

    public function search(CivilQuery $query): array
    {
        // the year of the event goes with the name: "Coret 1853", "Coret 1850-1860"
        $when = $query->dated
            ?? ($query->wantsAny(CivilRecordKind::DEATH, CivilRecordKind::BURIAL) && [] !== $query->kinds ? $query->died : null)
            ?? ($query->wantsAny(CivilRecordKind::BIRTH, CivilRecordKind::BAPTISM) && [] !== $query->kinds ? $query->born : null);
        // One kind is asked of the API (eventtype), several are sorted out here.
        // With a country too, the kind is sorted out here as well: the API drops
        // its country filter when both are given (seen with "be", 2026-10-04) -
        // more lines are asked for, so that enough of the kind are left.
        $event = 1 === \count($query->kinds) ? array_search($query->kinds[0], self::EVENTS, true) : false;
        $number = $query->limit;
        if (null !== $query->country && [] !== $query->kinds) {
            $event = false;
            $number = min(100, $query->limit * 5);
        }

        $answer = $this->ask('/records/search.json', array_filter([
            'name' => trim($query->name().' '.self::years($when)),
            'eventtype' => $event ?: null,
            'eventplace' => $query->place,
            'country_code' => null === $query->country ? null : strtolower($query->country),
            'number_show' => $number,
            'start' => $query->page > 1 ? ($query->page - 1) * $number : null,
            'lang' => $this->lang,
        ], static fn (mixed $value) => null !== $value && '' !== $value));

        $records = array_map($this->line(...), $answer['response']['docs'] ?? []);

        return \array_slice(array_values(array_filter($records, static fn (CivilRecord $record) => $query->wants($record->kind))), 0, $query->limit);
    }

    /** $identifier is "archive:uuid", as a search gives it. */
    public function find(string $identifier): ?CivilRecord
    {
        if (!preg_match('/^([a-z0-9]{2,10}):([\w{}-]{8,60})$/i', $identifier, $match)) {
            return null;
        }
        $answer = $this->ask('/records/show.json', ['archive' => strtolower($match[1]), 'identifier' => $match[2], 'lang' => $this->lang], true);

        return null === $answer || !isset($answer['Source']) ? null : $this->act($identifier, $answer);
    }

    private static function years(?Period $period): string
    {
        $from = $period?->from?->year;
        $to = $period?->to?->year;
        if (null === $from && null === $to) {
            return '';
        }
        $from ??= 1500;
        $to ??= (int) date('Y');

        return $from === $to ? (string) $from : $from.'-'.$to;
    }

    /** @return array<string, mixed>|null null: gone (find only) */
    private function ask(string $path, array $query, bool $find = false): ?array
    {
        $wait = self::$last + self::PACE - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        self::$last = microtime(true);

        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').$path, ['query' => $query, 'headers' => ['Accept' => 'application/json'], 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            if (429 === $status || $status >= 500) {
                throw new UnavailableException(sprintf('Open Archives answered %d.', $status), 429 === $status ? 1 : null);
            }
            if ($find && \in_array($status, [404, 410], true)) {
                return null;
            }
            $body = $response->getContent(false);
            $answer = '' === trim($body) ? [] : json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new UnavailableException('Open Archives did not answer: '.$e->getMessage(), null, $e);
        }
        if (isset($answer['error_code'])) {
            if ($find) {
                return null;   // an archive or a record it does not have
            }
            throw new OmnistateException(sprintf('Open Archives refused the question (%s): %s', $answer['error_code'], $answer['error_description'] ?? ''));
        }

        return $answer;
    }

    private static function kind(?string $event): CivilRecordKind
    {
        return self::EVENTS[$event ?? ''] ?? CivilRecordKind::OTHER;
    }

    /**
     * A line of a search: one person of one act.
     *
     * @param array<string, mixed> $doc
     */
    private function line(array $doc): CivilRecord
    {
        $place = $doc['eventplace'] ?? null;
        $place = \is_array($place) ? ($place[0] ?? null) : $place;
        $date = $doc['eventdate'] ?? [];

        return new CivilRecord(
            identifier: $doc['archive_code'].':'.$doc['identifier'],
            kind: self::kind($doc['_eventtype'] ?? $doc['eventtype'] ?? null),
            source: $this->name(),
            date: PartialDate::of((int) ($date['year'] ?? 0), (int) ($date['month'] ?? 0), (int) ($date['day'] ?? 0)),
            place: \is_string($place) && '' !== $place ? new Place($place) : null,
            persons: [new CivilPerson(role: $doc['relationtype'] ?? null, fullName: $doc['personname'] ?? null)],
            archive: new Archive((string) ($doc['archive_org'] ?? $doc['archive'] ?? $doc['archive_code']), (string) $doc['archive_code']),
            url: $doc['url'] ?? null,
            title: $doc['sourcetype'] ?? null,
            raw: $doc,
        );
    }

    /**
     * The whole act (the A2A model): everyone named, the reference, the scans.
     *
     * @param array<string, mixed> $a2a
     */
    private function act(string $identifier, array $a2a): CivilRecord
    {
        $list = static fn (mixed $value): array => \is_array($value) ? (array_is_list($value) ? $value : [$value]) : [];
        $event = $list($a2a['Event'] ?? null)[0] ?? [];
        $source = $a2a['Source'];

        $roles = [];
        foreach ($list($a2a['RelationEP'] ?? null) as $relation) {
            $roles[$relation['PersonKeyRef'] ?? ''] = $relation['RelationType'] ?? null;
        }
        $kind = self::kind($event['EventType'] ?? null);
        $persons = [];
        foreach ($list($a2a['Person'] ?? null) as $person) {
            $name = $person['PersonName'] ?? [];
            $given = trim((string) ($name['PersonNameFirstName'] ?? ''));
            $persons[] = new CivilPerson(
                familyName: trim(($name['PersonNamePrefixLastName'] ?? '').' '.($name['PersonNameLastName'] ?? '')),
                givenNames: '' === $given ? [] : preg_split('/\s+/', $given),
                sex: Sex::fromAny($person['Gender'] ?? null),
                role: $roles[$person['@pid'] ?? ''] ?? null,
                birthDate: self::date($person['BirthDate'] ?? null),
                birthPlace: isset($person['BirthPlace']['Place']) ? new Place($person['BirthPlace']['Place']) : null,
                age: isset($person['Age']['PersonAgeLiteral']) && is_numeric($person['Age']['PersonAgeLiteral']) ? (int) $person['Age']['PersonAgeLiteral'] : null,
                profession: $person['Profession'] ?? null,
                fullName: $name['PersonNameLiteral'] ?? null,
            );
        }
        // the one the act is about first: the child, the spouses, the deceased
        $main = ['Kind', 'Dopeling', 'Bruidegom', 'Bruid', 'Overledene', 'Geregistreerde'];
        usort($persons, static fn (CivilPerson $a, CivilPerson $b) => (\in_array($a->role, $main, true) ? 0 : 1) <=> (\in_array($b->role, $main, true) ? 0 : 1));

        $reference = $source['SourceReference'] ?? [];
        $images = [];
        foreach ($list($source['SourceAvailableScans']['Scan'] ?? null) as $scan) {
            if (isset($scan['Uri'])) {
                $images[] = $scan['Uri'];
            }
        }
        $place = $event['EventPlace']['Place'] ?? $source['SourcePlace']['Place'] ?? null;
        [$archive] = explode(':', $identifier, 2);

        return new CivilRecord(
            identifier: $identifier,
            kind: $kind,
            source: $this->name(),
            date: self::date($event['EventDate'] ?? null) ?? self::date($source['SourceDate'] ?? null),
            place: null === $place ? null : new Place($place, countryName: $source['SourcePlace']['Country'] ?? null),
            persons: $persons,
            archive: new Archive(
                (string) ($reference['InstitutionName'] ?? $archive),
                strtolower($archive),
                implode(', ', array_filter([$reference['Collection'] ?? null, $reference['Book'] ?? null, isset($reference['Folio']) ? 'folio '.$reference['Folio'] : null, isset($reference['DocumentNumber']) ? 'n° '.$reference['DocumentNumber'] : null])) ?: null,
                $source['SourceDigitalOriginal'] ?? null,
            ),
            url: 'https://www.openarchieven.nl/'.$identifier.('nl' === $this->lang ? '' : '/'.$this->lang),
            images: $images,
            title: $source['SourceType'] ?? null,
            number: isset($reference['DocumentNumber']) ? (string) $reference['DocumentNumber'] : null,
            raw: $a2a,
        );
    }

    /** @param array<string, mixed>|null $date */
    private static function date(?array $date): ?PartialDate
    {
        return null === $date ? null : PartialDate::of((int) ($date['Year'] ?? 0), (int) ($date['Month'] ?? 0), (int) ($date['Day'] ?? 0));
    }
}
