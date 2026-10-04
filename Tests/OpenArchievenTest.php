<?php

namespace Omnistate\OpenArchieven\Tests;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\Period;
use Omnistate\OpenArchieven\OpenArchieven;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** On answers recorded from api.openarchieven.nl on 2026-10-04. */
final class OpenArchievenTest extends TestCase
{
    public function testWhatItCovers(): void
    {
        $archives = new OpenArchieven(new MockHttpClient());

        self::assertSame('openarchieven', $archives->name());
        self::assertSame(['NL', 'BE', 'FR', 'SR'], $archives->countries());
        self::assertContains(CivilRecordKind::BIRTH, $archives->kinds());
        self::assertContains(CivilRecordKind::OTHER, $archives->kinds());
        self::assertNull($archives->period());
        self::assertTrue($archives->hasImages());

        self::assertTrue($archives->supports(new CivilQuery(familyName: 'van Gogh')));
        self::assertTrue($archives->supports(new CivilQuery(familyName: 'Janssens', country: 'be')));
        self::assertFalse($archives->supports(new CivilQuery(familyName: 'Smith', country: 'GB')));
        self::assertFalse($archives->supports(new CivilQuery(place: 'Zundert')), 'no name');
    }

    public function testTheLinesOfASearch(): void
    {
        $asked = null;
        $archives = new OpenArchieven(new MockHttpClient(function (string $method, string $url) use (&$asked) {
            $asked = urldecode($url);

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/search.json'));
        }));
        $records = $archives->search(new CivilQuery(familyName: 'van Gogh', givenName: 'Vincent Willem', dated: Period::year(1853), limit: 10));

        self::assertSame('https://api.openarchieven.nl/1.1/records/search.json?name=Vincent Willem van Gogh 1853&number_show=10&lang=en', $asked);
        self::assertCount(6, $records);

        $birth = $records[2];
        self::assertSame('bhi:78a37833-d731-ffe5-184c-f9f424826871', $birth->identifier);
        self::assertSame(CivilRecordKind::BIRTH, $birth->kind);
        self::assertSame('1853-03-30', (string) $birth->date);
        self::assertSame('Zundert', $birth->place?->name);
        self::assertSame('Vincent Willem van Gogh', $birth->principal()?->name());
        self::assertSame('Child', $birth->principal()?->role);
        self::assertSame('Brabants Historisch Informatie Centrum', $birth->archive?->name);
        self::assertSame('bhi', $birth->archive?->code);
        self::assertSame('Civil registration births', $birth->title);
        self::assertSame('https://www.openarchieven.nl/bhi:78a37833-d731-ffe5-184c-f9f424826871/en', $birth->url);

        self::assertSame(CivilRecordKind::OTHER, $records[0]->kind, 'a population register is not one of the five acts');
    }

    public function testOneKindIsAskedOfTheApiSeveralAreSortedOutHere(): void
    {
        $urls = [];
        $archives = new OpenArchieven(new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = urldecode(substr($url, \strlen(OpenArchieven::URL)));

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/'.(str_contains($url, 'Doop') ? 'baptisms' : 'search').'.json'));
        }));

        $baptisms = $archives->search(new CivilQuery(familyName: 'Jansen', born: Period::year(1750), kinds: [CivilRecordKind::BAPTISM], limit: 3));
        self::assertSame('/records/search.json?name=Jansen 1750&eventtype=Doop&number_show=3&lang=en', $urls[0]);
        self::assertCount(3, $baptisms);
        self::assertSame(CivilRecordKind::BAPTISM, $baptisms[0]->kind);
        self::assertSame('Mother', $baptisms[0]->principal()?->role);

        $births = $archives->search(new CivilQuery(familyName: 'van Gogh', kinds: [CivilRecordKind::BIRTH, CivilRecordKind::DEATH], place: 'Zundert', page: 2, limit: 10));
        self::assertSame('/records/search.json?name=van Gogh&eventplace=Zundert&number_show=10&start=10&lang=en', $urls[1]);
        self::assertSame([CivilRecordKind::BIRTH, CivilRecordKind::BIRTH], array_map(static fn ($r) => $r->kind, $births), 'the registrations are left out');

        // a kind and a country: the API would drop the country, so the kind is sorted out here, on more lines
        $dutch = $archives->search(new CivilQuery(familyName: 'van Gogh', country: 'NL', kinds: [CivilRecordKind::BIRTH], limit: 1));
        self::assertSame('/records/search.json?name=van Gogh&country_code=nl&number_show=5&lang=en', $urls[2]);
        self::assertCount(1, $dutch, 'cut to what was asked');
        self::assertSame(CivilRecordKind::BIRTH, $dutch[0]->kind);
    }

    public function testTheWholeAct(): void
    {
        $asked = null;
        $archives = new OpenArchieven(new MockHttpClient(function (string $method, string $url) use (&$asked) {
            $asked = urldecode($url);

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/show.json'));
        }));
        $act = $archives->find('bhi:78a37833-d731-ffe5-184c-f9f424826871');

        self::assertSame('https://api.openarchieven.nl/1.1/records/show.json?archive=bhi&identifier=78a37833-d731-ffe5-184c-f9f424826871&lang=en', $asked);
        self::assertSame(CivilRecordKind::BIRTH, $act?->kind);
        self::assertSame('1853-03-30', (string) $act->date);
        self::assertSame('Zundert', $act->place?->name);
        self::assertSame(['Vincent Willem van Gogh', 'Anna Cornelia Carbentus', 'Theodorus van Gogh'], array_map('strval', $act->persons), 'the child first');
        self::assertSame(['Kind', 'Moeder', 'Vader'], array_map(static fn ($p) => $p->role, $act->persons));
        self::assertSame('1853-03-30', (string) $act->principal()?->birthDate);
        self::assertSame('Brabants Historisch Informatie Centrum', $act->archive?->name);
        self::assertSame('Bron: boek, Deel: 9509, Periode: 1853, Geboorteregister Zundert 1853, n° 29', $act->archive?->reference);
        self::assertSame('https://www.bhic.nl/memorix/genealogy/search/deeds/78a37833-d731-ffe5-184c-f9f424826871', $act->archive?->url);
        self::assertSame('29', $act->number);
        self::assertSame(['https://images.memorix.nl/bhic/thumb/640x480/fab8c4f9-fa6f-cc20-c345-f5e21c1642d3.jpg'], $act->images);
        self::assertTrue($act->hasImages());
    }

    public function testNothingFoundAndARecordThatIsGone(): void
    {
        $none = new OpenArchieven(new MockHttpClient(new MockResponse(file_get_contents(__DIR__.'/Fixtures/none.json'))));
        self::assertSame([], $none->search(new CivilQuery(familyName: 'zzzzqqqxxnobody')));

        $gone = new OpenArchieven(new MockHttpClient(new MockResponse('', ['http_code' => 410])));
        self::assertNull($gone->find('bhi:00000000-0000-0000-0000-000000000000'));
        self::assertNull($gone->find('not an identifier'), 'not asked');

        $unknown = new OpenArchieven(new MockHttpClient(new MockResponse(file_get_contents(__DIR__.'/Fixtures/error.json'))));
        self::assertNull($unknown->find('nope:00000000-0000-0000-0000-000000000000'), 'an archive it does not have');
    }

    public function testAnErrorComesInTheBodyWithHttp200(): void
    {
        $this->expectException(OmnistateException::class);
        $this->expectExceptionMessage('Invalid archive');
        (new OpenArchieven(new MockHttpClient(new MockResponse(file_get_contents(__DIR__.'/Fixtures/error.json')))))->search(new CivilQuery(familyName: 'x'));
    }

    public function testTooManyCallsIsNotNobody(): void
    {
        try {
            (new OpenArchieven(new MockHttpClient(new MockResponse('', ['http_code' => 429]))))->search(new CivilQuery(familyName: 'Coret'));
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertSame(1, $e->retryAfter);
        }

        $this->expectException(UnavailableException::class);
        (new OpenArchieven(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']))))->search(new CivilQuery(familyName: 'Coret'));
    }

    public function testFourCallsASecondAtMost(): void
    {
        $archives = new OpenArchieven(new MockHttpClient(fn () => new MockResponse(file_get_contents(__DIR__.'/Fixtures/none.json'))));
        $start = microtime(true);
        for ($i = 0; $i < 4; ++$i) {
            $archives->search(new CivilQuery(familyName: 'Coret'));
        }

        self::assertGreaterThanOrEqual(0.75, microtime(true) - $start, 'three waits of a quarter of a second');
    }
}
