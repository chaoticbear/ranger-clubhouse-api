<?php

namespace App\Console\Commands;

use App\Lib\GroundHogDay;
use App\Lib\RedactDatabase;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ClubhouseCreateTrainingDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clubhouse:create-training-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a training db from production (config.mysql_clone_from). Left in place, and dumped to file.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $ghdVar = GroundHogDay::ENVIRONMENT_VAR;
        $groundHogDay = env($ghdVar);
        if (empty($groundHogDay)) {
            $this->error("$ghdVar environment variable not set");
            return true;
        }

        $dumpFile = GroundHogDay::trainingDatabaseDumpName($groundHogDay);
        $groundHogDay = Carbon::parse($groundHogDay);
        $year = $groundHogDay->year;

        $cloneUser = config('database.connections.mysql_clone_from.username');
        $clonePwd = config('database.connections.mysql_clone_from.password');
        $cloneDb = config('database.connections.mysql_clone_from.database');
        $cloneHost = config('database.connections.mysql_clone_from.host');
        putenv("MYSQL_PWD=$clonePwd");
        $cloneDump = "clone-dump.sql";

        $this->info("Dumping from $cloneDb");

        file_put_contents($cloneDump, "SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, AUTOCOMMIT = 0;
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS = 0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;
");
        $this->info("mysqldump -h $cloneHost -u $cloneUser --add-drop-table --single-transaction --skip-add-locks --quick --ignore-table=$cloneDb.log --ignore-table=$cloneDb.mail_log --ignore-table=$cloneDb.action_logs >> $cloneDump");

        if (shell_exec("mysqldump -h $cloneHost -u $cloneUser --add-drop-table --single-transaction --skip-add-locks --quick  --ignore-table=$cloneDb.person_message --ignore-table=$cloneDb.log --ignore-table=$cloneDb.mail_log --ignore-table=$cloneDb.action_logs $cloneDb >> $cloneDump")) {
            $this->error("Cannot dump the ignore database.");
            return true;
        }

        $this->info("Dumping partial schema");

        if (shell_exec("mysqldump -h $cloneHost -u $cloneUser --add-drop-table --no-data $cloneDb log mail_log person_message >> $cloneDump")) {
            $this->error("Cannot dump the database structure for selected tables.");
            return true;
        }

        $this->info("Dumping selected action log events");
        if (shell_exec("mysqldump -h $cloneHost -u $cloneUser   --single-transaction --skip-add-locks --quick --where=\"event in ('person-slot-add', 'person-slot-remove')\" $cloneDb action_logs  >> $cloneDump")) {
            $this->error("Cannot dump the database structure for selected tables.");
            return true;
        }

        file_put_contents($cloneDump, "
        SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
SET AUTOCOMMIT = @OLD_AUTOCOMMIT;
COMMIT;
", FILE_APPEND);

        // Load database up here.
        $user = config('database.connections.mysql.username');
        $pwd = config('database.connections.mysql.password');
        $db = config('database.connections.mysql.database');
        $host = config('database.connections.mysql.host');
        putenv("MYSQL_PWD=$pwd");
        $this->info("Loading into $db");
        if (shell_exec("mysql -h $host -u $user $db < $cloneDump")) {
            $this->error("Could not load database from production server");
            unlink($cloneDump);
            return 1;
        }

        $this->info('Redacting and Ground Hog Day-zing the database');
        RedactDatabase::execute($year);
        GroundHogDay::build($groundHogDay);

        unlink($cloneDump);
        $this->info("Dumping training database to $dumpFile");
        // password set above
        if (shell_exec("mysqldump -h $host -u $user $db | gzip > $dumpFile")) {
            $this->error("Failed to dump training database");
            unlink($dumpFile);
            return 1;
        }

        $this->info("Training database created. Database $db setup.");
        return 0;
    }
}
