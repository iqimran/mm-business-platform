<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Models\CarParty;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo car master data. Idempotent; refuses to run outside local/testing.
 */
class CarDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('CarDemoSeeder may only run in local or testing environments.');
        }

        foreach (['Paint', 'Tyres', 'Servicing', 'Registration', 'Cleaning'] as $name) {
            CarExpenseType::firstOrCreate(['name' => $name]);
        }

        $dealer = CarDealer::firstOrCreate(['name' => 'Demo Auto Traders'], ['phone' => '+8801700000001']);
        CarParty::firstOrCreate(['phone' => '+8801800000001'], ['name' => 'Demo Customer']);

        $branch = Branch::where('code', 'HQ')->first();
        if ($branch === null) {
            return;
        }

        $cars = [
            ['chassis_number' => 'NZE141-1000001', 'brand' => 'Toyota', 'model' => 'Corolla', 'model_year' => 2018, 'color' => 'White'],
            ['chassis_number' => 'GP5-2000002', 'brand' => 'Honda', 'model' => 'Fit Hybrid', 'model_year' => 2016, 'color' => 'Silver'],
        ];

        foreach ($cars as $car) {
            Car::firstOrCreate(
                ['chassis_number' => $car['chassis_number']],
                $car + ['branch_id' => $branch->id, 'dealer_id' => $dealer->id],
            );
        }
    }
}
