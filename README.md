# omnistate/openarchieven

Births, baptisms, marriages, deaths, burials and more from the indexes of some two hundred Dutch and
Belgian archives, through [Open Archives](https://www.openarchieven.nl), for
[glitchr/omnistate](https://github.com/glitchr-studio/omnistate).

```php
$registry = new OpenArchieven($httpClient);
$lines = $registry->search(new CivilQuery(familyName: 'van Gogh', givenName: 'Vincent Willem', dated: Period::year(1853), kinds: [CivilRecordKind::BIRTH]));
$act = $registry->find($lines[0]->identifier);      // "bhi:78a37833-…"
$act->persons;            // the child, the mother, the father - each with its role
$act->archive;            // Brabants Historisch Informatie Centrum, Geboorteregister Zundert 1853, n° 29
$act->images;             // the scan of the act
```

No framework needed: the package requires `glitchr/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

| | |
|---|---|
| Countries | NL, BE, FR (INSEE's deaths), SR - the country of the archive that holds the record |
| Kinds | births, baptisms, marriages, deaths, burials; everything else (population registers, notarial deeds, military records...) comes as OTHER |
| Years | whatever each archive indexed: from the 16th century church books to the 20th century |
| Picture of the act | yes, when the archive scanned it (`CivilRecord::$images`, given by `find()`) |
| Access | free, no key, open data; 4 calls a second per address (kept by the class) |

Documentation: [docs/](docs/index.md). License: LGPL-3.0-or-later.
