<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Foundation permissions. Business modules add their own permissions in their tasks.
 * Idempotent: safe to run in every environment.
 */
class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'user.view' => 'View users',
        'user.create' => 'Create users',
        'user.update' => 'Update users',
        'user.delete' => 'Delete users',
        'role.view' => 'View roles',
        'role.create' => 'Create roles',
        'role.update' => 'Update roles',
        'role.delete' => 'Delete roles',
        'branch.view' => 'View branches',
        'branch.create' => 'Create branches',
        'branch.update' => 'Update branches',
        'branch.access_all' => 'Access data of all branches (global access)',
        'setting.view' => 'View application settings',
        'setting.update' => 'Update application settings',
        'audit.view' => 'View audit logs',
        'car.view' => 'View cars',
        'car.create' => 'Create cars',
        'car.update' => 'Update cars and their images',
        'car.delete' => 'Delete cars',
        'car.dealer.view' => 'View car dealers',
        'car.dealer.create' => 'Create car dealers',
        'car.dealer.update' => 'Update car dealers',
        'car.dealer.delete' => 'Delete car dealers',
        'car.party.view' => 'View car parties (customers)',
        'car.party.create' => 'Create car parties',
        'car.party.update' => 'Update car parties',
        'car.party.delete' => 'Delete car parties',
        'car.expense_type.view' => 'View car expense types',
        'car.expense_type.create' => 'Create car expense types',
        'car.expense_type.update' => 'Update car expense types',
        'car.expense_type.delete' => 'Delete car expense types',
        'car.purchase.view' => 'View car purchases',
        'car.purchase.create' => 'Record car purchases',
        'car.purchase.reverse' => 'Reverse (correct) car purchases',
        'car.expense.view' => 'View car expenses',
        'car.expense.create' => 'Record car expenses',
        'car.expense.reverse' => 'Reverse (correct) car expenses',
        'car.sale.view' => 'View car sales',
        'car.sale.create' => 'Record car sales',
        'car.sale.reverse' => 'Reverse (correct) car sales',
        'car.payment.view' => 'View party (customer) payments',
        'car.payment.create' => 'Record party (customer) payments',
        'car.payment.reverse' => 'Reverse (correct) party payments',
        'car.dealer_payment.view' => 'View dealer payments',
        'car.dealer_payment.create' => 'Record dealer payments',
        'car.dealer_payment.reverse' => 'Reverse (correct) dealer payments',
        'car.status.update' => 'Change car lifecycle status (stock, preparation, ready, complete)',
        'car.document.view' => 'View car documents and expiry alerts',
        'car.document.create' => 'Add car documents (fitness, tax token, ...)',
        'car.document.update' => 'Update car documents',
        'car.document.delete' => 'Delete car documents',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::updateOrCreate(['name' => $name], ['description' => $description]);
        }
    }
}
