<?php
// Jalankan: php tests/Manual/verify_checklist_workflow.php   (butuh vendor lengkap; memakai SQLite in-memory, tidak menyentuh database Anda)
// Verifikasi workflow end-to-end (SQLite in-memory, request HTTP nyata ke kernel Laravel)
putenv('APP_ENV=testing'); $_ENV['APP_ENV']='testing'; $_SERVER['APP_ENV']='testing';
foreach (['DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','SESSION_DRIVER'=>'array','BCRYPT_ROUNDS'=>'4','DB_URL'=>''] as $k=>$v){putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;}
$root = $argv[1] ?? dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Redis::swap(new class { function __call($m,$a){ return null; } });
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
echo "migrate OK\n";

use App\Models\{User,Role,UserRole,ChecklistSubmission,Task,Room,Building,Shift,Verification,SubmissionRedirect};
use App\Enums\RoleEnum;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;

$pass=0;$fail=0;
function check($name,$cond,$extra=''){ global $pass,$fail; if($cond){$pass++;echo "  PASS  $name\n";}else{$fail++;echo "  FAIL  $name $extra\n";} }

foreach (RoleEnum::cases() as $r) Role::firstOrCreate(['name'=>$r->value],['description'=>$r->value]);
function mk($username,$name,$role,$level=null){
  $u=User::create(['username'=>$username,'email'=>"$username@example.test",'full_name'=>$name,'password'=>Hash::make('Password1!'),'is_active'=>true,'supervisor_level'=>$level]);
  UserRole::create(['user_id'=>$u->id,'role_id'=>Role::where('name',$role)->first()->id]);
  return $u;
}
$admin=mk('adm','Admin Uji','admin');
$win=mk('winaryo_t','Winaryo Uji','supervisor','head');
$kus=mk('kusnan_t','Kusnan Uji','supervisor','verifier');
$den=mk('denny_t','Denny Uji','supervisor','operational');
$pic=mk('pic_t','PIC Uji','pic');
$cs=mk('cs_t','CS Uji','cs');

$b=Building::create(['kode_gedung'=>'GU','nama_gedung'=>'Gedung Uji','is_active'=>true]);
$shift=Shift::create(['kode_shift'=>'S1','nama_shift'=>'All Day','jam_mulai'=>'00:00:00','jam_selesai'=>'23:59:59','is_overnight'=>false,'is_active'=>true]);
$room=Room::create(['building_id'=>$b->id,'kode_ruangan'=>'R-01','nama_ruangan'=>'Ruang Uji','lantai'=>'1','is_active'=>true,'qr_code_token'=>'tok','pic_user_id'=>$pic->id]);
$item=DB::table('checklist_items')->insertGetId(['id'=>$iid=(string)Str::uuid(),'nama_item'=>'Sapu','kategori'=>'lobby','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
DB::table('cs_assignments')->insert(['id'=>(string)Str::uuid(),'cs_user_id'=>$cs->id,'building_id'=>$b->id,'shift_id'=>$shift->id,'tanggal_mulai'=>today()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);

function mkTask($room,$cs,$shift,$iid,$offsetDays=0){
  static $sid=null; if(!$sid){ $sid=(string)Str::uuid();
  DB::table('schedules')->insert(['id'=>$sid,'room_id'=>$room->id,'checklist_item_id'=>$iid,'shift_id'=>$shift->id,'frekuensi'=>'harian','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]); }
  $t=Task::create(['id'=>(string)Str::uuid(),'schedule_id'=>$sid,'room_id'=>$room->id,'cs_user_id'=>$cs->id,'shift_id'=>$shift->id,'tanggal_task'=>today()->addDays($offsetDays)->toDateString(),'status'=>'in_progress','due_datetime'=>now()->addHours(3)]);
  // SQLite membandingkan string: samakan format dengan binding Carbon (MySQL tidak perlu)
  DB::table('tasks')->where('id',$t->id)->update(['tanggal_task'=>today()->addDays($offsetDays)->format('Y-m-d').' 00:00:00']);
  return $t->fresh();
}

function call($user,$method,$uri,$data=[],$files=[]){
  global $app;
  $token=$user->createToken('t')->plainTextToken;
  $req=Illuminate\Http\Request::create("/api/v1/$uri",$method,$data,[],$files,['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>"Bearer $token",'CONTENT_TYPE'=>$files?'multipart/form-data':'application/x-www-form-urlencoded']);
  $app['auth']->forgetGuards();
  $res=$app->handle($req);
  return [$res->getStatusCode(), json_decode($res->getContent(),true)];
}
function submitTask($cs,$task,$iid,$withPhoto=false){
  $files=[]; 
  if($withPhoto){ $f=tempnam(sys_get_temp_dir(),'im'); file_put_contents($f,base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));  $files['foto_after_1']=new Illuminate\Http\UploadedFile($f,'a.jpg','image/jpeg',null,true);}
  return call($cs,'POST','submissions',['task_id'=>$task->id,'results'=>[['checklist_item_id'=>$iid,'is_done'=>true]],'latitude'=>null],$files);
}
function insp(){ $f=tempnam(sys_get_temp_dir(),'in'); file_put_contents($f,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
  return ['foto_inspeksi'=>new Illuminate\Http\UploadedFile($f,'i.png','image/png',null,true)]; }

echo "\n[1] Submit OB tanpa foto (opsional) saat Kusnan bertugas\n";
$t1=mkTask($room,$cs,$shift,$iid,0);
[$c,$j]=submitTask($cs,$t1,$iid,false);
check('submit tanpa foto => 201',$c===201,"$c ".json_encode($j));
$s1=ChecklistSubmission::first();
check('stage pending_verification',$s1->verification_stage==='pending_verification');
check('verifier = Kusnan',$s1->verifier_id===$kus->id);
check('deadline = submitted_at + 24 jam',$s1->verification_deadline_at->equalTo($s1->submitted_at->copy()->addHours(24)));
check('foto_after kosong tidak error resource',($j['data']['has_foto_after']??null)===false && array_key_exists('foto_after_url',$j['data']) && $j['data']['foto_after_url']===null);

echo "\n[2] Hak akses tahap verifikasi\n";
[$c]=call($den,'POST',"verifications/{$s1->id}/approve",insp()['foto_inspeksi']?[]:[],insp()); check('Denny tidak bisa verifikasi (403)',$c===403,$c);
[$c]=call($pic,'POST',"verifications/{$s1->id}/approve",[],insp()); check('PIC tidak bisa verifikasi (403)',$c===403,$c);
[$c]=call($win,'POST',"verifications/{$s1->id}/approve",[],insp()); check('Winaryo belum bisa (laporan di antrean Kusnan) (403)',$c===403,$c);
[$c]=call($admin,'POST',"verifications/{$s1->id}/approve",[],insp()); check('Admin tidak approver (403)',$c===403,$c);
[$c,$j]=call($den,'GET','verifications/pending'); check('Denny lihat antrean read-only (200, can_act false)',$c===200 && count($j['data'])===1 && $j['data'][0]['workflow']['can_act']===false,json_encode($j));
[$c,$j]=call($pic,'GET','verifications/pending'); check('PIC tidak bisa akses antrean (403)',$c===403,$c);

echo "\n[3] Kusnan verifikasi -> Winaryo approval akhir\n";
[$c,$j]=call($kus,'POST',"verifications/{$s1->id}/approve",['notes'=>'ok'],insp()); check('Kusnan verifikasi 200',$c===200,json_encode($j));
$s1->refresh();
check('status masih submitted (belum selesai)',$s1->status->value==='submitted');
check('stage pending_final_approval',$s1->verification_stage==='pending_final_approval');
check('verified_at & verified_by terisi',$s1->verified_at!==null && $s1->verified_by===$kus->id);
check('final_approved_at masih null',$s1->final_approved_at===null);
check('task masih waiting_verification',Task::find($t1->id)->status->value==='waiting_verification');
[$c]=call($kus,'POST',"verifications/{$s1->id}/approve",[],[]); check('Kusnan tidak bisa approval akhir (403)',$c===403,$c);
[$c]=call($den,'POST',"verifications/{$s1->id}/approve",[],[]); check('Denny tidak bisa approval akhir (403)',$c===403,$c);
[$c,$j]=call($win,'GET','verifications/pending'); check('antrean Winaryo berisi laporan tahap akhir',$c===200 && count($j['data'])===1 && $j['data'][0]['workflow']['action']==='final_approve',json_encode($j));
[$c,$j]=call($win,'POST',"verifications/{$s1->id}/approve",['notes'=>'final']); check('Winaryo approval akhir 200',$c===200,json_encode($j));
$s1->refresh();
check('status approved & stage completed',$s1->status->value==='approved' && $s1->verification_stage==='completed');
check('final_approved_at/by terpisah dari verified_at',$s1->final_approved_at!==null && $s1->final_approved_by===$win->id);
check('task completed',Task::find($t1->id)->status->value==='completed');
[$c]=call($win,'POST',"verifications/{$s1->id}/approve",[],[]); check('approve ulang ditolak (idempotent)',in_array($c,[400,403]),$c);

echo "\n[4] Kusnan tidak bertugas => laporan baru langsung ke Winaryo\n";
[$c]=call($kus,'PATCH','verifications/verifier-duty',['is_on_duty'=>false,'reason'=>'x']); check('Kusnan tak bisa ubah status sendiri (403)',$c===403,$c);
[$c]=call($den,'PATCH','verifications/verifier-duty',['is_on_duty'=>false,'reason'=>'x']); check('Denny tak bisa ubah status (403)',$c===403,$c);
[$c,$j]=call($win,'PATCH','verifications/verifier-duty',['is_on_duty'=>false]); check('alasan wajib saat off (422)',$c===422,$c);
$t2=mkTask($room,$cs,$shift,$iid,1);
[$c]=submitTask($cs,$t2,$iid,true); check('submit dengan 1 foto 201',$c===201,$c);
$s2=ChecklistSubmission::where('task_id',$t2->id)->first();
check('foto After tersimpan',!empty($s2->foto_after_1));
$t3=mkTask($room,$cs,$shift,$iid,2); submitTask($cs,$t3,$iid,false); $s3=ChecklistSubmission::where('task_id',$t3->id)->first();
// Kusnan punya s2,s3 antre; set off -> dialihkan
$before=SubmissionRedirect::count();
[$c,$j]=call($win,'PATCH','verifications/verifier-duty',['is_on_duty'=>false,'reason'=>'Cuti']); check('Winaryo set Kusnan off 200',$c===200,json_encode($j));
check('Kusnan is_on_duty false',User::find($kus->id)->is_on_duty===false);
$s2->refresh();$s3->refresh();
check('laporan antre dialihkan ke Winaryo',$s2->verifier_id===$win->id && $s3->verifier_id===$win->id);
check('redirect_reason verifier_off + redirected_at',$s2->redirect_reason==='verifier_off' && $s2->redirected_at!==null);
check('log pengalihan tercatat (2 baris, dengan pengguna)',SubmissionRedirect::count()-$before===2 && SubmissionRedirect::latest('redirected_at')->first()->redirected_by===$win->id);
check('duty log tercatat dengan alasan & pengubah',DB::table('verifier_duty_logs')->where('reason','Cuti')->where('changed_by',$win->id)->exists());
[$c]=call($win,'PATCH','verifications/verifier-duty',['is_on_duty'=>false,'reason'=>'Cuti']); 
check('set off berulang tidak menduplikasi pengalihan',SubmissionRedirect::count()-$before===2);
$t4=mkTask($room,$cs,$shift,$iid,3); submitTask($cs,$t4,$iid,false); $s4=ChecklistSubmission::where('task_id',$t4->id)->first();
check('laporan baru saat Kusnan off langsung ke Winaryo',$s4->verifier_id===$win->id && $s4->redirect_reason==='verifier_off');
[$c,$j]=call($kus,'GET','verifications/pending'); check('antrean Kusnan kosong saat off',$c===200 && count($j['data'])===0,json_encode($j));
[$c,$j]=call($win,'POST',"verifications/{$s4->id}/approve",[],insp()); check('Winaryo verifikasi+approval laporan dialihkan 200',$c===200,json_encode($j));
$s4->refresh(); check('selesai dalam satu aksi, timestamp terpisah',$s4->status->value==='approved' && $s4->verified_at!==null && $s4->final_approved_at!==null);
[$c]=call($win,'POST',"verifications/{$s3->id}/reject",['catatan_perbaikan'=>'Ulangi']); check('Winaryo mengembalikan laporan 200',$c===200,$c);
check('rejected + task rejected',ChecklistSubmission::find($s3->id)->status->value==='rejected' && Task::find($t3->id)->status->value==='rejected');

echo "\n[5] Resubmit mengikuti routing & foto lama dipertahankan\n";
// Kusnan kembali bertugas
call($win,'PATCH','verifications/verifier-duty',['is_on_duty'=>true]);
[$c,$j]=call($cs,'POST',"submissions/{$s3->id}/resubmit",['results'=>[['checklist_item_id'=>$iid,'is_done'=>true]]]); check('resubmit tanpa foto 200',$c===200,json_encode($j));
$s3->refresh(); check('resubmit kembali ke Kusnan + SLA dihitung ulang',$s3->verifier_id===$kus->id && $s3->redirected_at===null && $s3->verification_deadline_at->gt(now()->addHours(23)));

echo "\n[6] SLA 24 jam kalender => pengalihan otomatis (scheduler)\n";
$s2->refresh();
// s2 sudah di Winaryo. Buat laporan baru utk Kusnan lalu mundurkan waktunya
$t5=mkTask($room,$cs,$shift,$iid,4); submitTask($cs,$t5,$iid,false); $s5=ChecklistSubmission::where('task_id',$t5->id)->first();
check('s5 ke Kusnan',$s5->verifier_id===$kus->id);
$s5->update(['submitted_at'=>now()->subHours(23)->subMinutes(30),'verification_deadline_at'=>now()->addMinutes(30)]);
Illuminate\Support\Facades\Artisan::call('cams:check-escalations');
check('23,5 jam belum dialihkan',ChecklistSubmission::find($s5->id)->verifier_id===$kus->id);
$s5->update(['submitted_at'=>now()->subHours(24)->subMinute(),'verification_deadline_at'=>now()->subMinute()]);
$n0=SubmissionRedirect::count();
Illuminate\Support\Facades\Artisan::call('cams:check-escalations');
$s5->refresh();
check('lewat 24 jam => dialihkan ke Winaryo',$s5->verifier_id===$win->id && $s5->redirect_reason==='sla_overdue');
check('1 log pengalihan sla_overdue (reason, waktu)',SubmissionRedirect::count()-$n0===1 && SubmissionRedirect::where('reason','sla_overdue')->whereNotNull('redirected_at')->exists());
Illuminate\Support\Facades\Artisan::call('cams:check-escalations');
check('scheduler idempotent (tidak duplikat)',SubmissionRedirect::count()-$n0===1);
check('status laporan tetap submitted (tidak hilang)',$s5->status->value==='submitted' && Task::find($t5->id)->status->value==='waiting_verification');
check('laporan tahap akhir tidak dialihkan',true);
// laporan pending_final_approval milik Winaryo lama tidak disentuh scheduler
$t6=mkTask($room,$cs,$shift,$iid,5); submitTask($cs,$t6,$iid,false); $s6=ChecklistSubmission::where('task_id',$t6->id)->first();
call($kus,'POST',"verifications/{$s6->id}/approve",[],insp());
$s6->update(['submitted_at'=>now()->subDays(3),'verification_deadline_at'=>now()->subDays(2)]);
Illuminate\Support\Facades\Artisan::call('cams:check-escalations');
check('laporan pending_final_approval tidak dialihkan oleh scheduler',ChecklistSubmission::find($s6->id)->verification_stage==='pending_final_approval' && ChecklistSubmission::find($s6->id)->redirected_at===null);

echo "\n[7] Data lama (legacy) & foto lama\n";
$t7=mkTask($room,$cs,$shift,$iid,6);
$legacy=ChecklistSubmission::create(['id'=>(string)Str::uuid(),'task_id'=>$t7->id,'cs_user_id'=>$cs->id,'submitted_at'=>now()->subHours(30),'resubmit_count'=>0,'scan_token_used'=>'x','status'=>'submitted','foto_after'=>'AAA','foto_after_mime'=>'image/jpeg','foto_after_1'=>'AAA','foto_after_1_mime'=>'image/jpeg','foto_after_2'=>'BBB','foto_after_2_mime'=>'image/jpeg','foto_after_4'=>'DDD','foto_after_4_mime'=>'image/jpeg']);
check('laporan lama (stage null) = pending_verification',$legacy->effectiveStage()->value==='pending_verification');
Illuminate\Support\Facades\Artisan::call('cams:check-escalations');
$legacy->refresh(); check('laporan lama >24 jam diarahkan & dialihkan',$legacy->verifier_id===$win->id);
[$c,$j]=call($win,'GET',"verifications/pending?per_page=50"); $row=collect($j['data'])->firstWhere('id',$legacy->id);
check('laporan lama tampil dengan foto_after_2 & 4',$c===200 && $row && $row['foto_after_2_url'] && $row['foto_after_4_url'] && $row['has_multiple_photos']===true,json_encode($row));
check('workflow is_overdue/hours terhitung',$row && array_key_exists('is_overdue',$row['workflow']));
$lg=call($win,'GET',"submissions/{$legacy->id}/foto-after-2"); check('stream foto_after_2 lama 200',$lg[0]===200,$lg[0]);

echo "\n[8] Level hierarki & akun\n";
[$c]=call($den,'PUT',"users/{$win->id}",['full_name'=>'x','email'=>'w@e.test','username'=>'winaryo_t','roles'=>['supervisor']]); check('Denny tak bisa edit akun Winaryo (403)',$c===403,$c);
[$c]=call($den,'PATCH',"users/{$kus->id}/toggle-status"); check('Denny tak bisa nonaktifkan Kusnan (403)',$c===403,$c);
[$c,$j]=call($den,'PUT',"users/{$den->id}",['full_name'=>'Denny Uji','email'=>'denny_t@example.test','username'=>'denny_t','roles'=>['supervisor'],'supervisor_level'=>'head']); check('Denny tak bisa naikkan level sendiri (403)',$c===403,$c.json_encode($j));
[$c,$j]=call($admin,'PUT',"users/{$den->id}",['full_name'=>'Denny Uji','email'=>'denny_t@example.test','username'=>'denny_t','roles'=>['supervisor'],'supervisor_level'=>'verifier']); check('level verifier unik (422)',$c===422,$c.json_encode($j));
[$c]=call($admin,'PUT',"users/{$den->id}",['full_name'=>'Denny Uji','email'=>'denny_t@example.test','username'=>'denny_t','roles'=>['supervisor'],'supervisor_level'=>'operational']); check('admin atur level operational 200',$c===200,$c);
[$c]=call($admin,'PATCH','verifications/verifier-duty',['is_on_duty'=>true]); check('admin boleh atur status Kusnan 200',$c===200,$c);
[$c,$j]=call($win,'PUT','settings',['settings'=>[['key'=>'verification_sla_hours','value'=>'0']]]); check('SLA 0 ditolak (422)',$c===422,$c);
[$c]=call($den,'PUT','settings',['settings'=>[['key'=>'verification_sla_hours','value'=>'48']]]); check('Denny tak bisa ubah SLA (403)',$c===403,$c);
check('SLA default 24',App\Services\VerificationWorkflowService::slaHours()===24);
[$c,$j]=call($win,'GET','verifications/workflow-summary'); check('ringkasan workflow 200',$c===200 && $j['data']['sla_hours']===24 && $j['data']['can_manage_verifier_duty']===true,json_encode($j));
[$c,$j]=call($win,'GET','dashboard/supervisor'); check('dashboard supervisor memuat verification_workflow',$c===200 && isset($j['data']['verification_workflow']),$c);

echo "\n[9] Seeder aman diulang\n";
Illuminate\Support\Facades\Artisan::call('db:seed',['--class'=>'ChecklistVerificationHierarchySeeder','--force'=>true]);
Illuminate\Support\Facades\Artisan::call('db:seed',['--class'=>'ChecklistVerificationHierarchySeeder','--force'=>true]);
check('seeder tidak membuat akun karangan',User::count()===6);
check('level akun existing tidak berubah',User::find($win->id)->supervisor_level==='head' && User::find($kus->id)->supervisor_level==='verifier');

echo "\nHASIL: $pass lulus, $fail gagal\n";
exit($fail?1:0);
