<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\Services\CityContextMatcher;
use App\Domain\Location\Services\PoiDisambiguationService;
use Psr\Log\NullLogger;
use Tests\TestCase;

class PoiDisambiguationTest extends TestCase
{
    private PoiDisambiguationService $disambiguator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disambiguator = new PoiDisambiguationService(
            cityMatcher: new CityContextMatcher,
            logger: new NullLogger,
        );
    }

    public function test_distinguishes_generic_poi_from_explicit_destination(): void
    {
        // Generic POIs
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Vision Express'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Tesco'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('McDonald\'s'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Costa'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Boots'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Starbucks'));
        $this->assertTrue($this->disambiguator->isGenericPoiQuery('Premier Inn'));

        // Explicit destinations with city/postcode/street/numbers
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Vision Express, Hounslow'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Vision Express, High Street, Hounslow'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Vision Express, 181-183 Oxford Street, London'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Vision Express, Whiston, Merseyside'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Tesco, Basildon, SS16 6ES'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('McDonald\'s, Telford, TF3 4AG'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Portland Street, Manchester'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('10 Downing Street, London'));
        $this->assertFalse($this->disambiguator->isGenericPoiQuery('Heathrow Airport (LHR)'));
    }

    public function test_rejects_distant_merseyside_candidate_when_pickup_at_heathrow(): void
    {
        $heathrow = new Coordinates(51.4700, -0.4543);

        $features = [
            [
                'id' => 'locality.204401231',
                'place_name' => 'Whiston, Merseyside, England, United Kingdom',
                'text' => 'Whiston',
                'center' => [-2.790232, 53.41682], // 172 miles straight-line away
            ],
            [
                'id' => 'address.12345',
                'place_name' => 'Express Way, Newbury, RG14 5TX, United Kingdom',
                'text' => 'Express Way',
                'center' => [-1.293218, 51.399926], // 42 miles away, name mismatch
            ],
        ];

        $eval = $this->disambiguator->evaluateCandidates(
            rawQuery: 'Vision Express',
            features: $features,
            proximity: $heathrow,
            referenceCity: 'London',
            maxPoiRadiusMiles: 35.0,
            confidenceThreshold: 0.60,
        );

        $this->assertEquals('AMBIGUOUS_NO_CANDIDATE', $eval['status']);
        $this->assertNull($eval['selected_feature']);
        $this->assertEquals(0.0, $eval['debug']['confidence_score']);
    }

    public function test_prefers_nearby_valid_candidate_over_distant_candidate(): void
    {
        $heathrow = new Coordinates(51.4700, -0.4543);

        $features = [
            [
                'id' => 'poi.distant',
                'place_name' => 'Tesco Extra, Edinburgh, EH12 9JR, United Kingdom',
                'text' => 'Tesco Extra',
                'center' => [-3.188267, 55.953252], // 330 miles away
            ],
            [
                'id' => 'poi.nearby',
                'place_name' => 'Tesco Express, High Street, Hounslow, TW3 1LD, United Kingdom',
                'text' => 'Tesco Express',
                'center' => [-0.36545, 51.47043], // ~3.8 miles away
                'context' => [
                    ['id' => 'place.1', 'text' => 'Hounslow'],
                    ['id' => 'region.1', 'text' => 'Greater London'],
                ],
            ],
        ];

        $eval = $this->disambiguator->evaluateCandidates(
            rawQuery: 'Tesco',
            features: $features,
            proximity: $heathrow,
            referenceCity: 'London',
            maxPoiRadiusMiles: 35.0,
            confidenceThreshold: 0.60,
        );

        $this->assertEquals('SELECTED', $eval['status']);
        $this->assertNotNull($eval['selected_feature']);
        $this->assertEquals('poi.nearby', $eval['selected_feature']['id']);
        $this->assertStringContainsString('Hounslow', $eval['selected_feature']['place_name']);
    }

    public function test_identifies_multiple_ambiguous_branches_in_same_metro_area(): void
    {
        $heathrow = new Coordinates(51.4700, -0.4543);

        $features = [
            [
                'id' => 'poi.branch1',
                'place_name' => 'Vision Express, Treaty Centre, Hounslow, TW3 1ES, United Kingdom',
                'text' => 'Vision Express',
                'center' => [-0.36336, 51.46764], // 4.1 miles
                'context' => [['id' => 'place.1', 'text' => 'Hounslow']],
            ],
            [
                'id' => 'poi.branch2',
                'place_name' => 'Vision Express, George Street, Richmond, TW9 1JY, United Kingdom',
                'text' => 'Vision Express',
                'center' => [-0.3045, 51.4602], // 6.5 miles
                'context' => [['id' => 'place.1', 'text' => 'Richmond']],
            ],
        ];

        $eval = $this->disambiguator->evaluateCandidates(
            rawQuery: 'Vision Express',
            features: $features,
            proximity: $heathrow,
            referenceCity: 'London',
            maxPoiRadiusMiles: 35.0,
            confidenceThreshold: 0.60,
        );

        // Multiple close candidates in the same metropolitan area triggers explicit disambiguation
        $this->assertEquals('AMBIGUOUS_MULTIPLE_CANDIDATES', $eval['status']);
        $this->assertNull($eval['selected_feature']);
        $this->assertCount(2, $eval['candidates']);
    }

    public function test_explicit_destination_bypasses_poi_radius_filter(): void
    {
        $manchesterAirport = new Coordinates(53.3588, -2.2727);

        $features = [
            [
                'id' => 'address.whiston',
                'place_name' => 'Vision Express, Warrington Road, Whiston, Prescot, L35 5DR, United Kingdom',
                'text' => 'Vision Express',
                'center' => [-2.790232, 53.41682],
                'context' => [['id' => 'place.1', 'text' => 'Prescot']],
            ],
        ];

        $eval = $this->disambiguator->evaluateCandidates(
            rawQuery: 'Vision Express, Whiston, Merseyside',
            features: $features,
            proximity: $manchesterAirport,
            referenceCity: 'Manchester',
            maxPoiRadiusMiles: 35.0,
        );

        $this->assertEquals('EXPLICIT', $eval['status']);
        $this->assertNotNull($eval['selected_feature']);
        $this->assertEquals('address.whiston', $eval['selected_feature']['id']);
    }
}
