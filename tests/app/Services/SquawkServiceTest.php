<?php

namespace App\Services;

use App\Allocator\Squawk\General\AirfieldPairingSquawkAllocator;
use App\Allocator\Squawk\General\CcamsSquawkAllocator;
use App\Allocator\Squawk\General\OrcamSquawkAllocator;
use App\Allocator\Squawk\Local\UnitDiscreteSquawkAllocator;
use App\BaseFunctionalTestCase;
use App\Events\SquawkAssignmentEvent;
use App\Events\SquawkUnassignedEvent;
use App\Models\Squawk\Ccams\CcamsSquawkAssignment;
use App\Models\Squawk\Ccams\CcamsSquawkRange;
use App\Models\Squawk\Orcam\OrcamSquawkAssignment;
use App\Models\Squawk\Orcam\OrcamSquawkRange;
use App\Models\Squawk\UnitDiscrete\UnitDiscreteSquawkRange;
use App\Models\Vatsim\NetworkAircraft;
use Carbon\Carbon;
use TestingUtils\Traits\WithSeedUsers;

class SquawkServiceTest extends BaseFunctionalTestCase
{
    use WithSeedUsers;

    /**
     * SquawkService
     *
     * @var SquawkService
     */
    private $squawkService;

    public function setUp(): void
    {
        parent::setUp();
        $this->squawkService = $this->app->make(SquawkService::class);
        Carbon::setTestNow(Carbon::now());
    }

    public function testItDeletesSquawks()
    {
        $this->expectsEvents(SquawkUnassignedEvent::class);

        // CCAMS shouldn't be called because ORCAM deleted a squawk
        OrcamSquawkAssignment::create(['callsign' => 'BAW123', 'code' => '0123']);
        CcamsSquawkAssignment::create(['callsign' => 'BAW123', 'code' => '0123']);

        $this->assertTrue($this->squawkService->deleteSquawkAssignment('BAW123'));

        $this->assertDatabaseMissing(
            'orcam_squawk_assignments',
            [
                'callsign' => 'BAW123',
            ]
        );
        $this->assertDatabaseHas(
            'ccams_squawk_assignments',
            [
                'callsign' => 'BAW123',
            ]
        );
    }

    public function testReturnsFalseOnNoSquawkDeleted()
    {
        $this->doesntExpectEvents(SquawkUnassignedEvent::class);
        $this->assertFalse($this->squawkService->deleteSquawkAssignment('BAW123'));
    }

    public function testItReturnsAssignedSquawk()
    {
        $assignment = OrcamSquawkAssignment::find(
            OrcamSquawkAssignment::create(['callsign' => 'BAW123', 'code' => '0123'])->callsign
        );
        $this->assertEquals($assignment, $this->squawkService->getAssignedSquawk('BAW123'));
    }

    public function testItReturnsNullOnNoAssignmentFound()
    {
        $this->assertNull($this->squawkService->getAssignedSquawk('BAW123'));
    }

    public function testItAssignsALocalSquawkAndReturnsIt()
    {
        $this->expectsEvents(SquawkAssignmentEvent::class);
        $assignment = $this->squawkService->assignLocalSquawk('BAW123', 'EGKK_APP', 'I');
        $this->assertEquals('0202', $assignment->getCode());
        $this->assertEquals('UNIT_DISCRETE', $assignment->getType());
        $this->assertEquals('BAW123', $assignment->getCallsign());
    }

    public function testItDoesntAssignLocalSquawkIfAllocatorFails()
    {
        $this->doesntExpectEvents(SquawkAssignmentEvent::class);
        UnitDiscreteSquawkRange::getQuery()->delete();
        $this->assertNull($this->squawkService->assignLocalSquawk('BAW123', 'EGKK_APP', 'I'));
    }

    public function testItAssignsAGeneralSquawkAndReturnsIt()
    {
        $this->expectsEvents(SquawkAssignmentEvent::class);
        $assignment = $this->squawkService->assignGeneralSquawk('BAW123', 'KJFK', 'EGLL');
        $this->assertEquals('0101', $assignment->getCode());
        $this->assertEquals('ORCAM', $assignment->getType());
        $this->assertEquals('BAW123', $assignment->getCallsign());
    }

    public function testItDoesntAssignGeneralSquawkIfAllocatorFails()
    {
        $this->doesntExpectEvents(SquawkAssignmentEvent::class);
        OrcamSquawkRange::getQuery()->delete();
        CcamsSquawkRange::getQuery()->delete();
        $this->assertNull($this->squawkService->assignGeneralSquawk('BAW123', 'EGKK', 'EGLL'));
    }

    public function testItTriesNextAllocatorIfGeneralAllocationFails()
    {
        $this->expectsEvents(SquawkAssignmentEvent::class);
        CcamsSquawkRange::create(
            [
                'first' => '0303',
                'last' => '0303',
            ]
        );
        OrcamSquawkRange::getQuery()->delete();

        $assignment = $this->squawkService->assignGeneralSquawk('BAW123', 'KJFK', 'EGLL');
        $this->assertEquals('0303', $assignment->getCode());
        $this->assertEquals('CCAMS', $assignment->getType());
        $this->assertEquals('BAW123', $assignment->getCallsign());
    }

    public function testDefaultGeneralAllocatorPreference()
    {
        $expected = [
            AirfieldPairingSquawkAllocator::class,
            OrcamSquawkAllocator::class,
            CcamsSquawkAllocator::class,
        ];

        $this->assertEquals($expected, $this->squawkService->getGeneralAllocatorPreference());
    }

    public function testDefaultLocalAllocatorPreference()
    {
        $expected = [
            UnitDiscreteSquawkAllocator::class,
        ];

        $this->assertEquals($expected, $this->squawkService->getLocalAllocatorPreference());
    }

    public function testSquawkReservationDoesNothingIfAircraftNotOnline()
    {
        CcamsSquawkRange::create(
            [
                'first' => '0303',
                'last' => '0303',
            ]
        );
        $this->squawkService->reserveSquawkForAircraft('UAE4');
        $this->assertDatabaseMissing(
            'ccams_squawk_assignments',
            [
                'callsign' => 'UAE4',
            ]
        );
    }

    public function testItReservesSquawkForAircraftNotCurrentlyAllocated()
    {
        $this->expectsEvents([SquawkAssignmentEvent::class]);
        $this->doesntExpectEvents([SquawkUnassignedEvent::class]);
        Carbon::setTestNow(Carbon::now());
        NetworkAircraft::where('callsign', 'BAW123')->update(
            [
                'transponder_last_updated_at' => Carbon::now()->subMinutes(2),
                'transponder' => '0303',
                'planned_depairport' => 'EDDF',
                'planned_destairport' => 'EGLL',
            ]
        );
        OrcamSquawkRange::create(
            [
                'origin' => 'ED',
                'first' => '0303',
                'last' => '0303',
            ]
        );
        $this->squawkService->reserveSquawkForAircraft('BAW123');
        $this->assertDatabaseHas(
            'orcam_squawk_assignments',
            [
                'callsign' => 'BAW123',
                'code' => '0303'
            ]
        );
    }

    public function testItReservesSquawkForAircraftCurrentlyAllocated()
    {
        $this->expectsEvents([SquawkUnassignedEvent::class, SquawkAssignmentEvent::class]);
        Carbon::setTestNow(Carbon::now());
        NetworkAircraft::where('callsign', 'BAW123')->update(
            [
                'transponder_last_updated_at' => Carbon::now()->subMinutes(2),
                'transponder' => '0303',
                'planned_depairport' => 'EDDF',
                'planned_destairport' => 'EGLL',
            ]
        );
        OrcamSquawkRange::create(
            [
                'origin' => 'ED',
                'first' => '0303',
                'last' => '0303',
            ]
        );
        CcamsSquawkAssignment::create(
            [
                'callsign' => 'BAW123',
                'code' => '0101'
            ]
        );

        $this->squawkService->reserveSquawkForAircraft('BAW123');
        $this->assertDatabaseMissing(
            'ccams_squawk_assignments',
            [
                'callsign' => 'BAW123',
            ]
        );
        $this->assertDatabaseHas(
            'orcam_squawk_assignments',
            [
                'callsign' => 'BAW123',
                'code' => '0303'
            ]
        );
    }

    public function testItDoesNothingIfNonAssignableCodeBeingSquawked()
    {
        $this->doesntExpectEvents([SquawkUnassignedEvent::class, SquawkAssignmentEvent::class]);
        Carbon::setTestNow(Carbon::now());
        NetworkAircraft::where('callsign', 'BAW123')->update(
            [
                'transponder_last_updated_at' => Carbon::now()->subMinutes(2),
                'transponder' => '7000',
                'planned_depairport' => 'EDDF',
                'planned_destairport' => 'EGLL',
            ]
        );
        OrcamSquawkRange::create(
            [
                'origin' => 'ED',
                'first' => '7000',
                'last' => '7000',
            ]
        );

        $this->squawkService->reserveSquawkForAircraft('BAW123');

        $this->assertDatabaseMissing(
            'orcam_squawk_assignments',
            [
                'callsign' => 'BAW123',
            ]
        );
    }
}
