# omnistate/openarchieven

## Installation

```sh
composer require glitchr/omnistate omnistate/openarchieven
```

In a Symfony application, `OmnistateBundle` registers it when it is installed; nothing to configure.

## What is asked

`GET https://api.openarchieven.nl/1.1/records/search.json`:

| Query | Parameter | |
|---|---|---|
| `givenName`, `familyName` | `name` | with the year or the span of the event after it: `Coret 1853`, `Coret 1850-1860` |
| `dated`; else `died` for deaths and burials, `born` for births and baptisms | in `name` | |
| one kind, no country | `eventtype` | in Dutch: `Geboorte`, `Doop`, `Huwelijk`, `Overlijden`, `Begraven` |
| `place` | `eventplace` | |
| `country` | `country_code` | `nl`, `be`, `fr`, `sr` (lower case); any other value is ignored by the API |
| `limit`, `page` | `number_show` (100 at most), `start` | |

Several kinds, or a kind with a country, are sorted out after the answer: the API drops its country
filter when `eventtype` is given with it (seen with `be` on 2026-10-04), so up to five times the lines
asked are fetched and those of the kinds wanted kept. Pages are then pages of the unsorted answer.

`find('archive:uuid')` asks `/records/show.json` and reads the A2A model.

## What comes back

- From `search()`: a line per person per act - the kind, the date and place of the event, the person's
  whole name (`CivilPerson::$fullName`) and role, the archive, the source type as `title`, the page.
- From `find()`: the whole act - every person with family name, given names, role (in Dutch, as the
  archive wrote it: `Kind`, `Vader`, `Moeder`, `Bruid`, `Bruidegom`, `Overledene`), birth date, age,
  profession; the archive's reference (collection, book, act number) and its own page; the scans.

## Errors

- HTTP 429 or 5xx, no answer, or a body that is not JSON: `UnavailableException` (`retryAfter` 1 s on a 429).
- An `error_code` in the body (the API answers them with HTTP 200): `OmnistateException` on a search,
  `null` on a `find()` (an archive or a record it does not have).
- HTTP 410 on `show.json`: the record is gone, `null`.

## Verified

Against the real service on 2026-10-04: search by name and year, the five event types, the four country
codes and their sum (the filter is a partition by archive country), a place, a record's details with
its scan, an unknown record (410), an invalid archive (error in the body). The fixtures in
`Tests/Fixtures/` are its answers.
