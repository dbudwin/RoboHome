<?php

namespace Tests\Unit\Controller\API;

use App\Device;
use App\DeviceActionInfo\IDeviceActionInfoBroker;
use App\Http\Globals\DeviceActions;
use App\Repositories\IDeviceRepository;
use App\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\TestResponse;
use Laravel\Passport\Passport;
use Mockery;
use ReflectionClass;
use Tests\Unit\Controller\Common\DevicesControllerTestCase;
use Webpatser\Uuid\Uuid;

class DevicesControllerTest extends DevicesControllerTestCase
{
    private $mockDeviceRepository;
    private $mockUser;
    private $messageId;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockDeviceRepository = Mockery::mock(IDeviceRepository::class);
        $this->mockUser = $this->createMockUser();
        $this->messageId = self::$faker->uuid();

        $this->app->instance(IDeviceRepository::class, $this->mockDeviceRepository);
    }

    public function testIndex_GivenUserExistsWithNoDevices_ReturnsJsonResponse(): void
    {
        $this->mockUser->shouldReceive('getAttribute')->with('devices')->once()->andReturn([]);

        $response = $this->callDevices();

        $this->assertDiscoverAppliancesResponseWithoutDevice($response, 'DiscoverAppliancesResponse');
    }

    public function testIndex_GivenUserExistsWithDevices_ReturnsJsonResponse(): void
    {
        $numberOfDevices = self::$faker->numberBetween(1, 10);
        $devices = $this->createDevices($numberOfDevices);

        $this->mockUser->shouldReceive('getAttribute')->with('devices')->once()->andReturn($devices);

        $response = $this->callDevices();

        $this->assertDiscoverAppliancesResponse($response, $devices);
    }

    public function testIndex_GivenUserDoesNotExist_Returns401(): void
    {
        $response = $this->getJson('/api/devices', [
            'HTTP_Authorization' => 'Bearer ' . self::$faker->uuid(),
            'HTTP_Message_Id' => $this->messageId
        ]);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'User not authenticated']);
    }

    public function testInfo_GivenUserExistsWithDeviceWithRandomScope_Returns400(): void
    {
        $deviceId = self::$faker->randomDigit();
        $action = self::$faker->word();
        $this->mockUser->shouldReceive('ownsDevice')->with($deviceId)->never();

        Passport::actingAs($this->mockUser, [self::$faker->word()]);

        $response = $this->callInfo($deviceId, $action);

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Missing scope']);
    }

    public function testInfo_GivenRandomUserThatExistsAndDeviceTheyDoNotOwn_Returns401(): void
    {
        $deviceUserDoesNotOwn = $this->createDevices()[0];
        $publicDeviceId = self::$faker->uuid();
        $action = self::$faker->word();
        $this->mockUserOwnsDevice($deviceUserDoesNotOwn->id, false);

        Passport::actingAs($this->mockUser, ['info']);

        $this->mockDeviceRepository->shouldReceive('getForPublicId')->with(Mockery::on(function (Uuid $argument) use ($publicDeviceId) {
            return $argument instanceof Uuid && $argument == Uuid::import($publicDeviceId);
        }))->once()->andReturn($deviceUserDoesNotOwn);

        $response = $this->callInfo($publicDeviceId, $action);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Unauthorized']);
    }

    public function testInfo_GivenUserExistsWithDevice_ReturnsJsonResponse(): void
    {
        $device = $this->createDevices()[0];
        $action = self::$faker->word();
        $this->mockUserOwnsDevice($device->id, true);

        Passport::actingAs($this->mockUser, ['info']);

        $mockDeviceActionInfoBroker = Mockery::mock(IDeviceActionInfoBroker::class);
        $mockDeviceActionInfoBroker
            ->shouldReceive('infoNeededToPerformDeviceAction')
            ->once()
            ->withArgs([$device, $action])
            ->andReturn(response()->json(self::$faker->word()));
        $this->app->instance(IDeviceActionInfoBroker::class, $mockDeviceActionInfoBroker);

        $this->mockDeviceRepository->shouldReceive('getForPublicId')->with(Mockery::on(function (Uuid $argument) use ($device) {
            return $argument instanceof Uuid && $argument == Uuid::import($device->public_id);
        }))->times(2)->andReturn($device);

        $response = $this->callInfo($device->public_id, $action);

        $response->assertSuccessful();
    }

    public function testControl_GivenUserExistsWithDevice_Returns200JsonResponse(): void
    {
        $device = $this->createDevice();

        $this->mockMessagePublisher(1);

        $action = $this->randomDeviceAction();

        $response = $this->callControl($action, $device->public_id);

        $response->assertSuccessful();
        $this->assertControlConfirmation($response, ucfirst($action . 'Confirmation'));
    }

    public function testControl_GivenUserExistsWithDevice_CallsPublishUnsuccessfully_Returns500(): void
    {
        $device = $this->createDevice();

        $this->mockMessagePublisher(1, false);

        $response = $this->callControl($this->randomDeviceAction(), $device->public_id);

        $response->assertStatus(500);
        $response->assertExactJson(['error' => 'Message not published']);
    }

    public function testControl_GivenUnknownDeviceActionForUserThatExistsWithDevice_Returns400(): void
    {
        $device = $this->createDevice();

        $this->mockMessagePublisher(1);

        $unknownAction = self::$faker->word();

        $response = $this->callControl($unknownAction, $device->public_id);

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Bad request']);
    }

    public function testControl_GivenUserExistsWithNoDevices_Returns401(): void
    {
        $device = $this->createDevice(false);

        $response = $this->callControl($this->randomDeviceAction(), $device->public_id);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Unauthorized']);
    }

    private function randomDeviceAction(): string
    {
        $deviceActionsReflectedClass = new ReflectionClass(DeviceActions::class);
        $deviceActions = $deviceActionsReflectedClass->getConstants();

        return $deviceActions[array_rand($deviceActions)];
    }

    private function callDevices(): TestResponse
    {
        Passport::actingAs($this->mockUser, ['control']);

        $response = $this->getJson('/api/devices', [
            'HTTP_Authorization' => 'Bearer ' . self::$faker->uuid(),
            'HTTP_Message_Id' => $this->messageId
        ]);

        return $response;
    }

    private function callInfo(string $publicDeviceId, string $action): TestResponse
    {
        $response = $this->postJson('/api/devices/info', [
            'action' => $action,
            'publicDeviceId' => $publicDeviceId
        ]);

        return $response;
    }

    private function callControl(string $action, string $publicDeviceId): TestResponse
    {
        $urlValidAction = strtolower($action);

        Passport::actingAs($this->mockUser, ['control']);

        $response = $this->postJson('/api/devices/control/' . $urlValidAction, ['publicDeviceId' => $publicDeviceId], [
            'HTTP_Authorization' => 'Bearer ' . self::$faker->uuid(),
            'HTTP_Message_Id' => $this->messageId
        ]);

        return $response;
    }

    private function createDevice(bool $isOwnedByUser = true): Device
    {
        $device = $this->createDevices()[0];
        $this->mockUserOwnsDevice($device->id, $isOwnedByUser);
        $this->mockDeviceRepository->shouldReceive('getForPublicId')->with(Mockery::on(function (Uuid $argument) use ($device) {
            return $argument instanceof Uuid && $argument == Uuid::import($device->public_id);
        }))->once()->andReturn($device);

        return $device;
    }

    private function assertDiscoverAppliancesResponseWithoutDevice(TestResponse $response, string $name): void
    {
        $response->assertExactJson([
            'header' => [
                'messageId' => $this->messageId,
                'name' => $name,
                'namespace' => 'Alexa.ConnectedHome.Discovery',
                'payloadVersion' => '2'
            ],
            'payload' => [
                'discoveredAppliances' => []
            ]
        ]);
    }

    private function assertDiscoverAppliancesResponse(TestResponse $response, Collection $devices): void
    {
        $appliances = [];

        for ($i = 0; $i < $devices->count(); $i++) {
            $appliances[] = [
                'actions' => [DeviceActions::TURN_ON, DeviceActions::TURN_OFF],
                'additionalApplianceDetails' => [],
                'applianceId' => $devices[$i]->id,
                'friendlyName' => $devices[$i]->name,
                'friendlyDescription' => $devices[$i]->description,
                'isReachable' => true,
                'manufacturerName' => 'N/A',
                'modelName' => 'N/A',
                'version' => 'N/A'
            ];
        }

        $response->assertExactJson([
            'header' => [
                'messageId' => $this->messageId,
                'name' => 'DiscoverAppliancesResponse',
                'namespace' => 'Alexa.ConnectedHome.Discovery',
                'payloadVersion' => '2'
            ],
            'payload' => [
                'discoveredAppliances' => $appliances
            ]
        ]);
    }

    private function assertControlConfirmation(TestResponse $response, string $name): void
    {
        $response->assertExactJson([
            'header' => [
                'messageId' => $this->messageId,
                'name' => $name,
                'namespace' => 'Alexa.ConnectedHome.Control',
                'payloadVersion' => '2'
            ],
            'payload' => []
        ]);
    }

    private function createDevices(int $numberOfDevices = 1): Collection
    {
        return factory(Device::class, $numberOfDevices)->make([
            'id' => self::$faker->randomNumber()
        ]);
    }

    private function createMockUser(): User
    {
        $publicUserId = self::$faker->uuid();

        $mockUser = $mockUser = Mockery::mock(User::class)->makePartial();
        $mockUser->shouldReceive('getAttribute')->with('public_id')->andReturn($publicUserId);

        return $mockUser;
    }

    private function mockUserOwnsDevice(int $deviceId, bool $userOwnsDevice): void
    {
        $this->mockUser->shouldReceive('ownsDevice')->with($deviceId)->once()->andReturn($userOwnsDevice);
    }
}
