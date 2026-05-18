<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class DatabaseSeeder extends Seeder {
    public function run(): void {
        if (DB::table('users')->count() > 0) {
            return;
        }

        DB::table('users')->insert([
            'id'=>'usr_admin','email'=>'admin@example.test','email_key'=>'admin@example.test','first_name'=>'Admin','last_name'=>'','role'=>'admin','password_hash'=>Hash::make('ChangeMe123!'), 'is_first_login'=>true,'active'=>true,'visibility'=>'offline','created_at'=>now(),'updated_at'=>now()
        ]);
        foreach ([['Anna Andersson','anna@example.test','0701111111','driver'],['Bo Berg','bo@example.test','0702222222','driver']] as $p) {
            DB::table('people')->insert(['id'=>(string) Str::ulid(),'name'=>$p[0],'email'=>$p[1],'phone'=>$p[2],'role'=>$p[3],'active'=>true,'source'=>'seed','created_at'=>now(),'updated_at'=>now()]);
        }
    }
}
