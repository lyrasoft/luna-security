<?php

declare(strict_types=1);

namespace Lyrasoft\Security\Command;

use Lyrasoft\Security\SecurityPackage;
use Lyrasoft\Toolkit\Spreadsheet\PhpSpreadsheetWriter;
use Lyrasoft\Toolkit\Spreadsheet\SpreadsheetKit;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Windwalker\Console\CommandInterface;
use Windwalker\Console\CommandWrapper;
use Windwalker\Console\IOInterface;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\Core\Manager\DatabaseManager;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Schema\Ddl\Column;
use Windwalker\Filesystem\Filesystem;
use Windwalker\Filesystem\Path;
use Windwalker\Utilities\Utf8String;

use function Windwalker\ds;

#[CommandWrapper(
    description: 'Export DN Schema to Excel file.'
)]
class DbExcelCommand implements CommandInterface, CompletionAwareInterface
{
    public function __construct(protected DatabaseManager $databaseManager, protected ApplicationInterface $app)
    {
    }

    /**
     * configure
     *
     * @param  Command  $command
     *
     * @return  void
     */
    public function configure(Command $command): void
    {
        $command->addArgument(
            'tables',
            InputArgument::IS_ARRAY,
            'The table names to export.'
        );

        $command->addOption(
            'output',
            '0',
            InputOption::VALUE_REQUIRED,
            'The output path',
            null
        );

        $command->addOption(
            'connection',
            'c',
            InputOption::VALUE_REQUIRED,
            'The db connection to use',
            null
        );

        $command->addOption(
            'def-lang',
            'l',
            InputOption::VALUE_REQUIRED,
            'Use default language',
            'en-US'
        );

        $command->addOption(
            'as-schema',
            's',
            InputOption::VALUE_NONE,
            'Export as LYRASOFY Schema template',
        );
    }

    /**
     * Executes the current command.
     *
     * @param  IOInterface  $io
     *
     * @return  int Return 0 is success, 1-255 is failure.
     */
    public function execute(IOInterface $io): int
    {
        $chooseTables = $io->getArgument('tables');
        $output = $io->getOption('output');
        $asSchema = (bool) $io->getOption('as-schema');
        $outputName = sprintf(
            'DbSchema-%s.xlsx',
            $this->app->getAppName(),
        );
        $useDefDesc = $io->getOption('def-lang');

        if (!$output) {
            $output = 'tmp/' . $outputName;
        }

        $dir = dirname($output);
        Filesystem::mkdir($dir);

        if (is_dir($output)) {
            $output .= '/' . $outputName;
        }

        $output = Path::realpath($output);

        $conn = $io->getOption('connection');
        $db = $this->databaseManager->get($conn);

        $excel = SpreadsheetKit::createPhpSpreadsheetWriter();

        if ($asSchema) {
            $tmpl = SecurityPackage::path('resources/data/table-schema-example.xlsx');

            $spreadsheet = IOFactory::load($tmpl);

            $excel->setCreateDriverHandler(fn() => $spreadsheet);

            $this->exportAsSchemaTemplate($excel, $db, $io);
        } else {
            /** @var Worksheet $sheet */
            $sheet = $excel->setActiveSheet(0);
            $sheet->setTitle('Summary');
            $sheet->freezePane('A2');

            $excel->addColumn('name', 'Name')->setWidth(15);
            $excel->addColumn('desc', 'Description')->setWidth(25);
            $tables = $db->getSchemaManager()->getTables();

            foreach ($tables as $table) {
                if ($chooseTables && !in_array($table->tableName, $chooseTables, true)) {
                    continue;
                }

                $excel->addRow(
                    function (PhpSpreadsheetWriter $row) use ($useDefDesc, $table) {
                        $desc = '';

                        if ($useDefDesc) {
                            $desc = $this->handleTableDescription($table->tableName, $useDefDesc);
                        }

                        $row->setRowCell('name', $table->tableName);
                        $row->setRowCell('desc', $desc);
                    }
                );
            }

            foreach ($tables as $table) {
                if ($chooseTables && !in_array($table->tableName, $chooseTables, true)) {
                    continue;
                }

                $query = $db->createQuery();
                $query->sql(
                    $query->format(
                        "SHOW FULL COLUMNS FROM %n",
                        $table->tableName
                    )
                );
                $columns = $query->all();

                /** @var Worksheet $worksheet */
                $worksheet = $excel->setActiveSheet($table->tableName);

                $worksheet->freezePane('B2');

                $excel->addColumn('name', 'name')->setWidth(20);
                $excel->addColumn('type', 'Type')->setWidth(15);
                $excel->addColumn('nullable', 'Nullable');
                $excel->addColumn('key', 'Key');
                $excel->addColumn('description', 'Description')->setWidth(30);

                foreach ($columns as $column) {
                    $excel->addRow(
                        function (PhpSpreadsheetWriter $row) use ($useDefDesc, $column) {
                            $row->setRowCell('name', $column->Field);
                            $row->setRowCell('type', $column->Type);
                            $row->setRowCell('nullable', $column->Null);
                            $row->setRowCell('key', $column->Key);

                            $desc = $column->Comment;

                            if ($useDefDesc) {
                                $desc = $this->handleDefaultColumnDescription($column->Field, $useDefDesc)
                                    ?: $column->Comment;
                            }

                            $row->setRowCell('description', $desc);
                        }
                    );
                }
            }
        }

        $excel->setActiveSheet(0);

        $excel->save($output, 'xlsx');

        $io->writeln('[Export to] ' . $output);

        return 0;
    }

    protected function exportAsSchemaTemplate(
        PhpSpreadsheetWriter $excel,
        DatabaseAdapter $db,
        IOInterface $io
    ) {
        ini_set('memory_limit', '3G');

        $chooseTables = $io->getArgument('tables');
        $useDefDesc = $io->getOption('def-lang');

        $spreadsheet = $excel->getDriver();

        $tables = $db->getSchemaManager()->getTables();

        foreach ($tables as $table) {
            if ($chooseTables && !in_array($table->tableName, $chooseTables, true)) {
                continue;
            }

            $sheet = clone $spreadsheet->getSheetByName('_Sample');
            $sheet->setTitle($table->tableName);
            $spreadsheet->addSheet($sheet);

            /** @var Worksheet $worksheet */
            $worksheet = $excel->setActiveSheet($table->tableName);

            $columns = $db->getTableManager($table->tableName)->getColumns(true, true);

            $worksheet->freezePane('B2');

            $excel->addColumn('name', 'Name')->setWidth(25);
            $excel->addColumn('type', 'Type');
            $excel->addColumn('length', 'Length');
            $excel->addColumn('nullable', 'NULL');
            $excel->addColumn('signed', 'Signed');
            $excel->addColumn('default', 'Default');
            $excel->addColumn('relation', 'Relation');
            $excel->addColumn('key', 'Key');
            $excel->addColumn('key_name', 'Key Name');
            $excel->addColumn('description', 'Description');
            $excel->addColumn('note', 'Note');

            foreach ($columns as $column) {
                $excel->addRow(
                    function (PhpSpreadsheetWriter $row) use ($useDefDesc, $column) {
                        $row->setRowCell('name', $column->columnName);
                        $row->setRowCell('type', $this->getDataTypeValue($column));
                        $row->setRowCell('length', $this->getLengthValue($column));
                        $row->setRowCell('nullable', $column->getIsNullable() ? 'Allow' : null);
                        $row->setRowCell('signed', $column->getNumericUnsigned() ? 'Unsigned' : null);
                        $row->setRowCell('default', $this->getDefaultValue($column));
                        $row->setRowCell('key', $this->getKeyOptionValue($column));

                        $desc = $column->getComment();

                        if ($useDefDesc) {
                            $desc = $this->handleDefaultColumnDescription($column->columnName, $useDefDesc)
                                ?: $column->getComment();
                        }

                        $row->setRowCell('description', $desc);
                    }
                );
            }
        }
    }

    protected function getDataTypeValue(Column $column): string
    {
        $type = $column->getDataType();

        if ($type === 'tinyint' && (string) $column->getErratas()['custom_length'] === '1') {
            return 'bool';
        }

        return $type;
    }

    protected function getLengthValue(Column $column): ?string
    {
        $length = $column->getLengthExpression();
        $type = $column->getDataType();

        if (
            in_array(
                $type,
                [
                    'int',
                    'bigint',
                    'tinyint',
                    'text',
                    'mediumtext',
                    'longtext',
                    'date',
                    'datetime',
                    'timestamp',
                    'time',
                    'year',
                    'blob',
                ],
                true
            )
        ) {
            return null;
        }

        if ($type === 'varchar' && $length === '255') {
            return null;
        }

        if ($type === 'char' && $length === '255') {
            return null;
        }

        return $length;
    }

    protected function getDefaultValue(Column $column): ?string
    {
        $type = $column->getDataType();
        // $length = $column->getLengthExpression();
        $def = (string) $column->getColumnDefault();

        if (
            $def === '0'
            && in_array($type, ['int', 'bigint', 'tinyint', 'bool', 'decimal', 'float', 'double'])
        ) {
            return null;
        }

        return $def;
    }

    protected function getKeyOptionValue(Column $column): ?string
    {
        if ($column->isPrimary()) {
            if ($column->isAutoIncrement()) {
                return 'Primary (AI)';
            }

            return 'Primary';
        }

        if (array_any($column->constraints, fn($constraint) => $constraint->isUnique())) {
            return 'Unique';
        }

        if (count($column->indexes)) {
            return 'Index';
        }

        return null;
    }

    protected function handleDefaultColumnDescription(string $columnName, string $useDefDesc): string
    {
        if ($useDefDesc === 'zh-TW') {
            return $this->handleZhTWColumnDescription($columnName);
        }

        $desc = $this->handleEnUsColumnDescription($columnName);

        if (!$desc) {
            $desc = Utf8String::ucwords($columnName);
        }

        return $desc;
    }

    /**
     * @param  string  $columnName
     *
     * @return  string
     */
    protected function handleZhTWColumnDescription(string $columnName): string
    {
        return match ($columnName) {
            'action' => '行為',
            'activation' => '認證碼',
            'address' => '地址',
            'alias' => '網址用別名',
            'allow' => '允許',
            'alt' => '替代文字',
            'assignee_id' => '被指派人員',
            'avatar' => '頭像',
            'category_id' => '分類 ID',
            'code' => '代碼',
            'content' => '內容',
            'created' => '建立時間',
            'created_by' => '建立者',
            'css' => 'CSS',
            'data' => '資料',
            'description', 'desc' => '介紹',
            'dest' => '目的位置',
            'email' => 'Email',
            'enabled' => '啟用',
            'end_time', 'end_date' => '結束時間',
            'extends' => '繼承自',
            'extra' => '額外資訊',
            'first_name' => '名',
            'fulltext' => '全文',
            'hash' => '雜湊值',
            'hidden' => '隱藏',
            'id' => 'ID',
            'identifier' => '識別 ID',
            'image' => '圖片',
            'intro' => '簡介',
            'introtext' => '摘要',
            'job_title' => '職稱',
            'key' => '鍵名',
            'kind' => '類別',
            'language' => '語言',
            'last_login' => '最後登入時間',
            'last_name' => '姓',
            'last_reset' => '最後重設時間',
            'level' => '層級',
            'lft' => '左鍵',
            'link' => '連結',
            'meta' => 'Metadata',
            'mime' => '媒體類型',
            'mobile' => '手機',
            'mobile_image' => '手機版圖片',
            'mobile_video' => '手機版影片',
            'modified' => '修改時間',
            'modified_by' => '修改者',
            'name' => '名稱',
            'note' => '備註',
            'ordering' => '排序',
            'page_id' => '頁面 ID',
            'params' => '其他參數',
            'parent_id' => '上層 ID',
            'password' => '密碼',
            'path' => '路徑',
            'phone' => '電話',
            'position' => '位置',
            'prefix' => '前綴',
            'range' => '範圍',
            'receive_mail' => '可收到系統信件',
            'registered' => '註冊時間',
            'remember' => '記住',
            'reset_token' => '重設令牌',
            'rgt' => '右鍵',
            'role_id' => '身分',
            'serial' => '序號',
            'size' => '大小',
            'src' => '來源位置',
            'start_time', 'start_date' => '開始時間',
            'state' => '狀態',
            'status' => '狀態代碼',
            'subtitle' => '副標題',
            'subtype' => '子類型',
            'tag_id' => '標籤 ID',
            'target' => '目標',
            'target_id' => '目標 ID',
            'time' => '時間',
            'title' => '標題',
            'title_native' => '本地標題',
            'type' => '類型',
            'url' => 'URL',
            'user_id' => '使用者',
            'username' => '帳號',
            'variables' => '變數',
            'verified' => '已認證',
            'version' => '版本',
            'video' => '影片',
            'video_type' => '影片類型',
            'view' => '視圖',
            'details' => '細節',
            'provider' => '提供者',
            'sitename' => '網站名稱',
            default => '',
        };
    }

    /**
     * @param  string  $columnName
     *
     * @return  string
     */
    protected function handleEnUsColumnDescription(string $columnName): string
    {
        return match ($columnName) {
            'activation' => 'Activation Code',
            'alias' => 'URL Alias',
            'alt' => 'Alt Text',
            'assignee_id' => 'Assignee ID',
            'category_id' => 'Category ID',
            'created' => 'Created Time',
            'created_by' => 'Created User ID',
            'dest' => 'Destination',
            'desc' => 'Description',
            'extends' => 'Extends From',
            'extra' => 'Extra Data',
            'id' => 'ID',
            'last_login' => 'Last Login time',
            'last_reset' => 'Last Reset time',
            'meta' => 'Metadata',
            'mime' => 'MIME Type',
            'modified' => 'Modified Time',
            'modified_by' => 'Modified User ID',
            'page_id' => 'Page ID',
            'params' => 'Parameters',
            'parent_id' => 'Parent ID',
            'receive_mail' => 'Can receive system mail',
            'registered' => 'Registered Time',
            'role_id' => 'Role',
            'src' => 'Source',
            'tag_id' => 'Tag ID',
            'target_id' => 'Target ID',
            'url' => 'URL',
            'user_id' => 'User ID',
            default => '',
        };
    }

    protected function handleTableDescription(string $tableName, mixed $useDefDesc): string
    {
        if ($useDefDesc === 'zh-TW') {
            return match ($tableName) {
                'articles' => '文章',
                'associations' => '語言或其他關連',
                'categories' => '分類',
                'configs' => '設定檔',
                'languages' => '語言',
                'menus' => '選單',
                'migration_log' => '資料庫版本管理',
                'page_templates' => '頁面模版',
                'pages' => '頁面',
                'rules' => '權限規則',
                'sessions' => '使用者會話',
                'tag_maps' => '標籤對照',
                'tags' => '標籤',
                'user_role_maps' => '身分對照',
                'user_roles' => '身分',
                'user_socials' => '社群資訊',
                'users' => '使用者',
                'widgets' => '小工具',

                // Event Booking
                'venues' => '場館',
                'event_attends' => '活動參與者',
                'event_member_maps' => '活動主講者',
                'event_orders' => '活動訂單',
                'event_plans' => '活動方案',
                'event_stages' => '活動梯次',
                'events' => '活動',

                // Members
                'members' => '成員',

                // Portfolio
                'portfolios' => '作品',

                // Contact
                'contacts' => '聯絡我們',

                // Firewall
                'ip_rules' => 'IP 規則',
                'redirects' => '重導向',

                // Sequences
                'sequences' => '序列號管理',

                // Attachment
                'attachments' => '檔案',

                // Banner
                'banners' => '橫福',

                default => '',
            };
        }

        return match ($tableName) {
            default => '',
        };
    }

    public function completeOptionValues($optionName, CompletionContext $context)
    {
    }

    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName === 'tables') {
            $words = $context->getWords();

            if (false !== $i = array_search('--connection', $words, true)) {
                $conn = $context->getWordAtIndex($i + 1);
            } elseif (false !== $i = array_search('-c', $words, true)) {
                $conn = $context->getWordAtIndex($i + 1);
            } else {
                $conn = null;
            }

            $db = $this->databaseManager->get($conn);

            $tables = $db->getSchemaManager()->getTables();

            return array_map(
                fn($table) => $table->tableName,
                $tables
            );
        }
    }
}
