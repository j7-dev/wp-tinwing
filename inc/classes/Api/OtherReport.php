<?php
/**
 * OtherReport Api Register
 */

declare (strict_types = 1);

namespace J7\WpTinwing\Api;

use J7\WpUtils\Classes\WP;
use J7\WpTinwing\Utils\Base;
use J7\WpTinwing\Plugin;
/**
 * Class Entry
 */
final class OtherReport
{
    use \J7\WpUtils\Traits\SingletonTrait;
    use \J7\WpUtils\Traits\ApiRegisterTrait;

    /**
     * Trial Balance 費用科目與 Tinwing 支出分類（expense_class）的對應
     *
     * 來源：客戶 2026-09-14 提供的「RAN-Trial Balance-2.xlsx」Expenses Catagories 工作表。
     * key 是系統裡的分類名稱，value 是 Trial Balance 的 Account Name，多個分類可累加到同一個科目。
     *
     * 刻意用「名稱」而不是 slug 或 ID 比對：分類大多是從「Others N」空位改名而來，slug 沒跟著改
     * （例如 Audit Fee 的 slug 是 misc、Gift 的 slug 是 others-3），用 slug 會對錯科目。
     * 比對前兩邊都會經過 normalize_expense_class_name()，大小寫、en dash、隱形字元都不影響。
     * 名稱為「Others 數字」的分類不列在這裡，統一歸到 TRIAL_BALANCE_EXPENSE_OTHERS_ITEM。
     */
    private const TRIAL_BALANCE_EXPENSE_MAP = [
        'Golf (entertainment )'                      => 'Entertainment',
        'Loan to director'                           => 'Loan to Director',
        'Bank Audit Charge'                          => 'Bank Charges',
        'Bank Charges'                               => 'Bank Charges',
        'Bonus'                                      => 'Bonus',
        'Business Registration'                      => 'Business Registration',
        'CPD – Study Allowance'                      => 'Study Allowance',
        'Director Remuneration – Li Tsun Sun'        => 'Director Remuneration - Li Tsun Sun',
        'Petty Cash – Entertainment'                 => 'Entertainment',
        'Insurance'                                  => 'Insurance',
        'Management Fee'                             => 'Management Fee',
        'Lucky Money'                                => 'Lucky Money',
        'Audit Fee'                                  => 'Audit Fee',
        'MPF'                                        => 'MPF',
        'New Computer System'                        => 'New Computer System',
        'MPF – Li Chung Chai'                        => 'Salary - Li Chung Chai',
        'MPF – Lai Yuen Chun'                        => 'Salary - Lai Yuen Chun',
        'Gift'                                       => 'Gift',
        'Repairs & Maintenance'                      => 'Repairs & Maintenance',
        'Petty Cash – Electricity / Wate Fee / Gas.' => 'Electricity, Water Fee & Gas',
        'Petty Cash – Gift'                          => 'Gift',
        'Petty Cash – Medical'                       => 'Medical Expenese',
        'Petty Cash – Repair & Maintance'            => 'Repairs & Maintenance',
        'Petty Cash – Postage / Stamp'               => 'Stamp & Postage',
        'Petty Cash – Rent & Rates'                  => 'Rent & Rates',
        'Petty Cash – Sundry'                        => 'Sundry Expenses',
        'Petty Cash – Telephone'                     => 'Telephone Fax & Internet Fee',
        'Petty Cash – Travel'                        => 'Travel Expenses',
        'Commission – D/R Li Tsun Sun'               => 'Director Remuneration - Li Tsun Sun',
        'Pretty Cash – Printing & Stationery'        => 'Printing & Stationery',
        'Printing'                                   => 'Printing & Stationery',
        'Salary – Li Chung Chai'                     => 'Salary - Li Chung Chai',
        'Salary – Lai Yuen Chun'                     => 'Salary - Lai Yuen Chun',
        'Visa – Electricity / Wate Fee / Gas.'       => 'Electricity, Water Fee & Gas',
        'Visa – Entertainment'                       => 'Entertainment',
        'Visa – Gift'                                => 'Gift',
        'Visa – Medical'                             => 'Medical Expenese',
        'Visa – Tax'                                 => 'Tax',
        'Visa – Postage / Stamp'                     => 'Stamp & Postage',
        'Visa – Rent & Rates'                        => 'Rent & Rates',
        'Visa – Sundry'                              => 'Sundry Expenses',
        'Visa – Tel / Fax / Internet Fee'            => 'Telephone Fax & Internet Fee',
        'Visa – BR'                                  => 'Business Registration',
        'Visa – Travel'                              => 'Travel Expenses',
    ];

    /**
     * 名稱為「Others 數字」的自訂分類在 Trial Balance 上顯示的科目
     */
    private const TRIAL_BALANCE_EXPENSE_OTHERS_ITEM = 'Misc';

    /**
     * 已由 A/C Payable 各列計算的保險公司付款分類（slug），費用科目彙總時略過
     */
    private const TRIAL_BALANCE_INSURER_PAYMENT_SLUGS = [
        'insurer-payment-msig',
        'insurer-payment-tokio',
        'insurer-payment-cmb',
        'insurer-payment-taiping',
    ];

    /**
     * 記在 expenses、但其實不是支出的 expense_class（slug）
     *
     * Other Earning – Rebate 是收到的回佣（錢進來），Trial Balance 的 No.27
     * Rebate-Received / Others 已改由 other_earnings CPT 提供，這個舊分類不該再被
     * 當成銀行支出，也不該進任何費用科目。
     */
    private const TRIAL_BALANCE_NON_EXPENSE_SLUGS = [
        'other-earning-rebate',
    ];

    /**
     * expense_class slug 對應 term ID 的暫存（同一次請求內共用）
     *
     * @var array<string, array<int>>
     */
    private $expense_class_term_ids_cache = [];

    /**
     * 每張 receipt 對應的單據與單據日期的暫存（同一次請求內共用），見 get_receipt_source_notes()
     *
     * @var array<int, array{note: \WP_Post, is_credit_note: bool, timestamp: int}>|null
     */
    private $receipt_source_notes_cache = null;

    /**
     * 取得指定 expense_class slug 對應的 term ID（查一次記起來）
     *
     * @param array<string> $slugs
     * @return array<int>
     */
    private function get_expense_class_term_ids_by_slugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $cache_key = implode(',', $slugs);
        if (isset($this->expense_class_term_ids_cache[$cache_key])) {
            return $this->expense_class_term_ids_cache[$cache_key];
        }

        $term_ids = (new \WP_Query([
            'post_type' => 'terms',
            'post_name__in' => $slugs,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'taxonomy',
                    'value' => 'expense_class',
                    'compare' => '='
                ]
            ]
        ]))->posts;

        $this->expense_class_term_ids_cache[$cache_key] = array_map('intval', $term_ids);

        return $this->expense_class_term_ids_cache[$cache_key];
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        \add_action('rest_api_init', [$this, 'register_api_other_reports']);
    }

    /**
     * Get APIs
     *
     * @return array
     * - endpoint: string
     * - method: 'get' | 'post' | 'patch' | 'delete'
     * - permission_callback : callable
     */
    protected function get_apis()
    {
        return [
            [
                'endpoint'            => 'client_ageing_report',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'insurer_ageing_report',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'report_by_agent',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'report_by_principal_and_class',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'profit_and_loss_analysis',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'trial_balance',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],
            [
                'endpoint'            => 'balance_sheet',
                'method'              => 'get',
                'permission_callback' => '__return_true', // TODO 應該是特定會員才能看
            ],

        ];
    }

    /**
     * Register products API
     *
     * @return void
     */
    public function register_api_other_reports(): void
    {
        $this->register_apis(
            apis: $this->get_apis(),
            namespace :Plugin::$kebab,
            default_permission_callback: fn() => \current_user_can('manage_options'),
        );

    }
    /**
     * Get clients_summary callback
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_client_ageing_report_callback($request)
    { // phpcs:ignore

        $params = $request->get_query_params() ?? [];
        $params = WP::sanitize_text_field_deep($params, false);
        // error_log(print_r($params, true));
        // 查詢 Custom Post Type 'debit_notes' 和 'credit_notes' 的文章
        $args = [
            'post_type'      => ['debit_notes', 'credit_notes'],                                   // 自定義文章類型名稱
            'posts_per_page' => isset($params['posts_per_page']) ? $params['posts_per_page'] : -1, // 每頁顯示文章數量
            'paged'          => isset($params['page']) ? $params['page'] : 1,                      // 當前頁碼
            'orderby'        => isset($params['orderby']) ? $params['orderby'] : 'id',             // 排序方式
            'order'          => isset($params['order']) ? $params['order'] : 'desc',               // 排序順序（DESC: 新到舊，ASC: 舊到新）
        ];
        // 如果有meta_query 參數，則加入查詢條件
        if (isset($params['meta_query'])) {
            $meta_query         = Base::sanitize_meta_query($params['meta_query']);
            $args['meta_query'] = $meta_query;
        }
        //加入排除條件,receipt_id不存在或為空
        $args['meta_query'][] = [
            'key'     => 'receipt_id',
            'value'   => '',
            'compare' => 'NOT EXISTS',
        ];
        // error_log(print_r($args, true));
        //主查詢
        $query = new \WP_Query($args);

        //取得client ids資料
        $client_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $client_ids[] = get_post_meta(get_the_ID(), 'client_id', true);
            }
        }
        $client_ids = array_values(array_unique($client_ids));
        //取得client 資料
        $client_map = [];
        if ($client_ids) {
            $client_data = get_posts([
                'post_type'              => 'clients',
                'post__in'               => $client_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($client_data as $client) {
                $client_map[$client->ID] = $client;
            }
        }
        //取得agent ids資料
        $agent_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $agent_ids[] = get_post_meta(get_the_ID(), 'agent_id', true);
            }
        }
        $agent_ids = array_values(array_unique($agent_ids));
        //取得agent 資料
        $agent_map = [];
        if ($agent_ids) {
            $agent_data = get_posts([
                'post_type'              => 'agents',
                'post__in'               => $agent_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($agent_data as $agent) {
                $agent_map[$agent->ID] = $agent;
            }
        }

        // 整合資料
        $posts_data = [];
        if ($query->have_posts()) {
            $total_premium = 0;
            $total_120_days = 0;
            $total_90_days = 0;
            $total_60_days = 0;
            $total_30_days = 0;
            
            while ($query->have_posts()) {
                $query->the_post();
                $client       = $client_map[get_post_meta(get_the_ID(), 'client_id', true)];
                $agent        = $agent_map[get_post_meta(get_the_ID(), 'agent_id', true)];
                $display_name = get_post_meta($client->ID, 'display_name', true);
                $phone_keys   = ['mobile1', 'mobile2', 'tel2', 'tel3'];
                $phone        = '';
                foreach ($phone_keys as $key) {
                    $val = get_post_meta($client->ID, $key, true);
                    if (! empty($val)) {
                        $phone = $val;
                        break; // 找到第一個非空就結束
                    }
                }
                $premium = (float) (get_post_meta(get_the_ID(), 'premium', true) ?: 0);
                $total_premium += $premium;
                
                // 計算日期差異 (考慮WordPress時區)
                $post_date = get_post_meta(get_the_ID(), 'date', true);
                $wp_timezone = wp_timezone(); // 取得 WP 設定的時區
                $current_date = current_time('timestamp'); // 使用WordPress時區的當前時間戳
                $days_diff = 0;
                
                if ($post_date) {
                    // 將post_date轉換為WordPress時區的日期
                    $post_datetime = new \DateTime('@' . $post_date);
                    $post_datetime->setTimezone($wp_timezone);
                    
                    // 將當前時間轉換為WordPress時區的日期
                    $current_datetime = new \DateTime('@' . $current_date);
                    $current_datetime->setTimezone($wp_timezone);
                    
                    // 計算天數差異
                    $post_date_only = $post_datetime->format('Y-m-d');
                    $current_date_only = $current_datetime->format('Y-m-d');
                    
                    $post_date_obj = new \DateTime($post_date_only, $wp_timezone);
                    $current_date_obj = new \DateTime($current_date_only, $wp_timezone);
                    
                    $interval = $current_date_obj->diff($post_date_obj);
                    $days_diff = $interval->days;
                }
                
                // 根據日期差異分配到對應的區間
                $days_120_over = '';
                $days_90_over = '';
                $days_60_over = '';
                $days_30_over = '';
                
                if ($days_diff >= 120) {
                    $days_120_over = number_format($premium, 2, '.', ',');
                    $total_120_days += $premium;
                } elseif ($days_diff >= 90) {
                    $days_90_over = number_format($premium, 2, '.', ',');
                    $total_90_days += $premium;
                } elseif ($days_diff >= 60) {
                    $days_60_over = number_format($premium, 2, '.', ',');
                    $total_60_days += $premium;
                } elseif ($days_diff >= 30) {
                    $days_30_over = number_format($premium, 2, '.', ',');
                    $total_30_days += $premium;
                }
                
                $posts_data[] = [
                    // 'id'              => get_the_ID(),
                    'Client Code'     => $client ? $client->post_title : '',
                    'Client Name'     => $client ? get_post_meta($client->ID, $display_name, true) ?? '' : '',
                    '120Days & Over'  => $days_120_over,
                    '90Days & Over'   => $days_90_over,
                    '60Days & Over'   => $days_60_over,
                    '30Days & Over'   => $days_30_over,
                    'Current Balance' => number_format($premium, 2, '.', ',') ?? '',
                    'Advance Pay'     => '',
                    'Agent'           => $agent ? get_post_meta($agent->ID, 'agent_number', true) ?? '' : '',
                    'Phone'           => $phone,
                ];
            }
            $posts_data[] = [
                // 'id'=>'',
                'Client Code'     => '',
                'Client Name'     => 'Total',
                '120Days & Over'  => $total_120_days > 0 ? number_format($total_120_days, 2, '.', ',') : '',
                '90Days & Over'   => $total_90_days > 0 ? number_format($total_90_days, 2, '.', ',') : '',
                '60Days & Over'   => $total_60_days > 0 ? number_format($total_60_days, 2, '.', ',') : '',
                '30Days & Over'   => $total_30_days > 0 ? number_format($total_30_days, 2, '.', ',') : '',
                'Current Balance' => number_format($total_premium, 2, '.', ','),
                'Advance Pay'     => '',
                'Agent'           => '',
                'Phone'           => '',
            ];
            wp_reset_postdata();
        }
        // error_log(print_r($posts_data, true));
        $response = new \WP_REST_Response($posts_data);
        $total    = $query->found_posts !== 0 ? $query->found_posts + 1 : $query->found_posts;
        // Set pagination in header.
        $response->header('X-WP-Total', $total);
        // $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }
    /**
     * Get insurer_ageing_report callback
     * 找出還沒收到錢的insurer = receipt.is_paid = false
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_insurer_ageing_report_callback($request)
    {
        $params = $request->get_query_params() ?? [];
        $params = WP::sanitize_text_field_deep($params, false);
        // 查詢 Custom Post Type 'receipts' 的文章
        $args = [
            'post_type'      => ['receipts'],                                                      // 自定義文章類型名稱
            'posts_per_page' => isset($params['posts_per_page']) ? $params['posts_per_page'] : -1, // 每頁顯示文章數量
            'paged'          => isset($params['page']) ? $params['page'] : 1,                      // 當前頁碼
            'orderby'        => isset($params['orderby']) ? $params['orderby'] : 'id',             // 排序方式
            'order'          => isset($params['order']) ? $params['order'] : 'desc',               // 排序順序（DESC: 新到舊，ASC: 舊到新）
        ];
        // 如果有meta_query 參數，則加入查詢條件
        if (isset($params['meta_query'])) {
            $meta_query         = Base::sanitize_meta_query($params['meta_query']);
            $args['meta_query'] = $meta_query;
        }
        //加入排除條件,is_paid = 0
        $args['meta_query'][] = [
            'key'     => 'is_paid',
            'compare' => '=',
            'value'   => '0',
        ];
        // error_log(print_r($args, true));
        //主查詢
        $query = new \WP_Query($args);

        //取得insurer 資料
        $insurer_map = [];
        if ($query->have_posts()) {
            $insurer_data = get_posts([
                'post_type'              => 'insurers',
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($insurer_data as $insurer) {
                $insurer_map[$insurer->ID] = $insurer;
            }
        }
        //取得debit_note ids資料
        $debit_note_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $debit_note_ids[] = get_post_meta(get_the_ID(), 'debit_note_id', true);
            }
        }
        $debit_note_ids = array_values(array_unique($debit_note_ids));
        //取得debit_note 資料
        $debit_note_map = [];
        if ($debit_note_ids) {
            $debit_note_data = get_posts([
                'post_type'              => 'debit_notes',
                'post__in'               => $debit_note_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($debit_note_data as $debit_note) {
                $debit_note_map[$debit_note->ID] = $debit_note;
            }
        }
        //取得credit_note ids資料
        $credit_note_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $credit_note_ids[] = get_post_meta(get_the_ID(), 'created_from_credit_note_id', true);
            }
        }
        $credit_note_ids = array_values(array_unique($credit_note_ids));
        //取得credit_note 資料
        $credit_note_map = [];
        if ($credit_note_ids) {
            $credit_note_data = get_posts([
                'post_type'              => 'credit_notes',
                'post__in'               => $credit_note_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($credit_note_data as $credit_note) {
                $credit_note_map[$credit_note->ID] = $credit_note;
            }
        }
        //取得renewal ids資料
        $renewal_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $renewal_ids[] = get_post_meta(get_the_ID(), 'created_from_renewal_id', true);
            }
        }
        $renewal_ids = array_values(array_unique($renewal_ids));
        //取得renewal 資料
        $renewal_map = [];
        if ($renewal_ids) {
            $renewal_data = get_posts([
                'post_type'              => 'renewals',
                'post__in'               => $renewal_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($renewal_data as $renewal) {
                $renewal_map[$renewal->ID] = $renewal;
            }
        }
        // 整合資料
        $posts_data = [];
        if ($query->have_posts()) {
            $total_insurer_payment = 0;
            $total_120_days = 0;
            $total_90_days = 0;
            $total_60_days = 0;
            $total_30_days = 0;
            
            while ($query->have_posts()) {
                $query->the_post();
                $debit_note  = $debit_note_map[get_post_meta(get_the_ID(), 'debit_note_id', true)];
                $credit_note = $credit_note_map[get_post_meta(get_the_ID(), 'created_from_credit_note_id', true)];
                $renewal     = $renewal_map[get_post_meta(get_the_ID(), 'created_from_renewal_id', true)];
                $the_note    = $credit_note ?? $renewal ?? $debit_note;
                $insurer     = $insurer_map[get_post_meta($the_note->ID, 'insurer_id', true)];
                //如果沒有insurer,則跳過
                if (! $insurer) {
                    continue;
                }
                $insurer_id      = $insurer ? $insurer->ID : '';
                $insurer_payment = $this->get_insurer_payment($the_note, $insurer);
                if ($credit_note) {
                    $insurer_payment = -$insurer_payment;
                }
                $total_insurer_payment += $insurer_payment;
                
                // 計算日期差異 (考慮WordPress時區)
                $note_date = get_post_meta($the_note->ID, 'date', true);
                $wp_timezone = wp_timezone(); // 取得 WP 設定的時區
                $current_date = current_time('timestamp'); // 使用WordPress時區的當前時間戳
                $days_diff = 0;
                
                if ($note_date) {
                    // 將note_date轉換為WordPress時區的日期
                    $note_datetime = new \DateTime('@' . $note_date);
                    $note_datetime->setTimezone($wp_timezone);
                    
                    // 將當前時間轉換為WordPress時區的日期
                    $current_datetime = new \DateTime('@' . $current_date);
                    $current_datetime->setTimezone($wp_timezone);
                    
                    // 計算天數差異
                    $note_date_only = $note_datetime->format('Y-m-d');
                    $current_date_only = $current_datetime->format('Y-m-d');
                    
                    $note_date_obj = new \DateTime($note_date_only, $wp_timezone);
                    $current_date_obj = new \DateTime($current_date_only, $wp_timezone);
                    
                    $interval = $current_date_obj->diff($note_date_obj);
                    $days_diff = $interval->days;
                }
                
                // 根據日期差異分配到對應的區間
                $days_120_payment = 0;
                $days_90_payment = 0;
                $days_60_payment = 0;
                $days_30_payment = 0;
                
                if ($days_diff >= 120) {
                    $days_120_payment = $insurer_payment;
                    $total_120_days += $insurer_payment;
                } elseif ($days_diff >= 90) {
                    $days_90_payment = $insurer_payment;
                    $total_90_days += $insurer_payment;
                } elseif ($days_diff >= 60) {
                    $days_60_payment = $insurer_payment;
                    $total_60_days += $insurer_payment;
                } elseif ($days_diff >= 30) {
                    $days_30_payment = $insurer_payment;
                    $total_30_days += $insurer_payment;
                }
                
                // error_log('get_the_ID():'.get_the_ID());
                // error_log('insurer_payment:'.$insurer_payment);
                //如果$posts_data[$insurer_id]存在,則累加各區間與'Current Balance'
                if (isset($posts_data[$insurer_id])) {
                    $posts_data[$insurer_id]['120Days & Over'] += $days_120_payment;
                    $posts_data[$insurer_id]['90Days & Over'] += $days_90_payment;
                    $posts_data[$insurer_id]['60Days & Over'] += $days_60_payment;
                    $posts_data[$insurer_id]['30Days & Over'] += $days_30_payment;
                    $posts_data[$insurer_id]['Current Balance'] += $insurer_payment;
                } else {
                    $posts_data[$insurer_id] = [
                        'A/C No'          => $insurer ? get_post_meta($insurer->ID, 'insurer_number', true) : '',
                        'Type'            => '',
                        'Creditor Name'   => $insurer ? $insurer->post_title : '',
                        '120Days & Over'  => $days_120_payment,
                        '90Days & Over'   => $days_90_payment,
                        '60Days & Over'   => $days_60_payment,
                        '30Days & Over'   => $days_30_payment,
                        'Current Balance' => $insurer_payment,
                    ];
                }
            }
            $posts_data[] = [
                'A/C No'          => '',
                'Type'            => '',
                'Creditor Name'   => 'Total',
                '120Days & Over'  => $total_120_days,
                '90Days & Over'   => $total_90_days,
                '60Days & Over'   => $total_60_days,
                '30Days & Over'   => $total_30_days,
                'Current Balance' => $total_insurer_payment,
            ];
            $posts_data = array_values($posts_data);
            foreach ($posts_data as $key => $value) {
                $posts_data[$key]['120Days & Over']  = $value['120Days & Over'] != 0 ? number_format($value['120Days & Over'], 2, '.', ',') : '';
                $posts_data[$key]['90Days & Over']   = $value['90Days & Over'] != 0 ? number_format($value['90Days & Over'], 2, '.', ',') : '';
                $posts_data[$key]['60Days & Over']   = $value['60Days & Over'] != 0 ? number_format($value['60Days & Over'], 2, '.', ',') : '';
                $posts_data[$key]['30Days & Over']   = $value['30Days & Over'] != 0 ? number_format($value['30Days & Over'], 2, '.', ',') : '';
                $posts_data[$key]['Current Balance'] = number_format($value['Current Balance'], 2, '.', ',');
            }
            wp_reset_postdata();
        }

        $response = new \WP_REST_Response($posts_data);
        $total    = count($posts_data);
        // Set pagination in header.
        $response->header('X-WP-Total', $total);
        // $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }
    /**
     * report_by_agent callback
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_report_by_agent_callback($request)
    {
        $params = $request->get_query_params() ?? [];
        $params = WP::sanitize_text_field_deep($params, false);
        // error_log(print_r($params, true));
        // 查詢 Custom Post Type 'debit_notes' 和 'credit_notes' 的文章
        $args = [
            'post_type'      => ['debit_notes', 'credit_notes'],                                   // 自定義文章類型名稱
            'posts_per_page' => isset($params['posts_per_page']) ? $params['posts_per_page'] : -1, // 每頁顯示文章數量
            'paged'          => isset($params['page']) ? $params['page'] : 1,                      // 當前頁碼
            'orderby'        => isset($params['orderby']) ? $params['orderby'] : 'id',             // 排序方式
            'order'          => isset($params['order']) ? $params['order'] : 'desc',               // 排序順序（DESC: 新到舊，ASC: 舊到新）
        ];
        // 如果有meta_query 參數，則加入查詢條件
        if (isset($params['meta_query'])) {
            $meta_query         = Base::sanitize_meta_query($params['meta_query']);
            $args['meta_query'] = $meta_query;
        }
        //加入agent_id條件
        if (isset($params['agent_id'])) {
            $args['meta_query'][] = [
                'key'     => 'agent_id',
                'value'   => $params['agent_id'],
                'compare' => '=',
            ];
        }
        //加入payment_status條件
        if (isset($params['payment_status']) && $params['payment_status'] == 'unpaid') {
            //加入排除條件,receipt_id不存在或為空
            $args['meta_query'][] = [
                'key'     => 'receipt_id',
                'value'   => '',
                'compare' => 'NOT EXISTS',
            ];
        } elseif (isset($params['payment_status']) && $params['payment_status'] == 'paid') {
            //加入條件,receipt_id存在
            $args['meta_query'][] = [
                'key'     => 'receipt_id',
                'compare' => 'EXISTS',
            ];
        }

        // error_log(print_r($args, true));
        //主查詢
        $query = new \WP_Query($args);

        //取得agent ids資料
        $agent_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $agent_ids[] = get_post_meta(get_the_ID(), 'agent_id', true);
            }
        }
        $agent_ids = array_values(array_unique($agent_ids));
        //取得agent 資料
        $agent_map = [];
        if ($agent_ids) {
            $agent_data = get_posts([
                'post_type'              => 'agents',
                'post__in'               => $agent_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($agent_data as $agent) {
                $agent_map[$agent->ID] = $agent;
            }
        }
        //取得client ids資料
        $client_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $client_ids[] = get_post_meta(get_the_ID(), 'client_id', true);
            }
        }
        $client_ids = array_values(array_unique($client_ids));
        //取得client 資料
        $client_map = [];
        if ($client_ids) {
            $client_data = get_posts([
                'post_type'              => 'clients',
                'post__in'               => $client_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($client_data as $client) {
                $client_map[$client->ID] = $client;
            }
        }
        //取得receipt ids資料
        $receipt_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $receipt_ids[] = get_post_meta(get_the_ID(), 'receipt_id', true);
            }
        }
        $receipt_ids = array_values(array_unique($receipt_ids));
        //取得receipt 資料
        $receipt_map = [];
        if ($receipt_ids) {
            $receipt_data = get_posts([
                'post_type'              => 'receipts',
                'post__in'               => $receipt_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($receipt_data as $receipt) {
                $receipt_map[$receipt->ID] = $receipt;
            }
        }
        // 整合資料
        $posts_data = [];
        if ($query->have_posts()) {
            $total_premium = 0;
            while ($query->have_posts()) {
                $query->the_post();
                $agent        = $agent_map[get_post_meta(get_the_ID(), 'agent_id', true)];
                $client       = $client_map[get_post_meta(get_the_ID(), 'client_id', true)];
                $receipt      = $receipt_map[get_post_meta(get_the_ID(), 'receipt_id', true)];
                $display_name = get_post_meta($client->ID, 'display_name', true);
                $agent_id     = $agent ? $agent->ID : '';
                $client_id    = $client ? $client->ID : '';
                $post_type    = get_post_type(get_the_ID());
                $premium      = (float) (get_post_meta(get_the_ID(), 'premium', true) ?: 0);
                $total_premium += $premium;
                $posts_data[] = [
                    'Date'         => get_post_meta(get_the_ID(), 'date', true) ? \date_i18n('d/m/y', get_post_meta(get_the_ID(), 'date', true)) : 'N/A',
                    'Note No'      => get_the_title(),
                    'Post type'    => $post_type === 'debit_notes' ? 'DN' : 'CN',
                    'Client Name'  => $client ? get_post_meta($client->ID, $display_name, true) ?? '' : '',
                    'Premium'      => $premium,
                    'Agent Code'   => $agent ? get_post_meta($agent->ID, 'agent_number', true) ?? '' : '',
                    'Receipt No'   => $receipt ? $receipt->post_title : '',
                    'Payment Date' => $receipt ? \date_i18n('d/m/y', get_post_meta($receipt->ID, 'payment_date', true)) : '',
                ];
            }
            $posts_data[] = [
                'Date'         => '',
                'Note No'      => '',
                'Post type'    => '',
                'Client Name'  => 'Total',
                'Premium'      => $total_premium,
                'Agent Code'   => '',
                'Receipt No'   => '',
                'Payment Date' => '',
            ];
            foreach ($posts_data as $key => $value) {
                $posts_data[$key]['Premium'] = number_format($value['Premium'], 2, '.', ',');
            }
            wp_reset_postdata();
        }
        $response = new \WP_REST_Response($posts_data);
        $total    = $query->found_posts !== 0 ? $query->found_posts + 1 : $query->found_posts;
        // Set pagination in header.
        $response->header('X-WP-Total', $total);
        // $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }
    /**
     * report_by_principal_and_class callback
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_report_by_principal_and_class_callback($request)
    {
        $params = $request->get_query_params() ?? [];
        $params = WP::sanitize_text_field_deep($params, false);
        // error_log(print_r($params, true));
        // 查詢 Custom Post Type 'debit_notes' 和 'credit_notes' 的文章
        $args = [
            'post_type'      => ['debit_notes', 'credit_notes'],                                   // 自定義文章類型名稱
            'posts_per_page' => isset($params['posts_per_page']) ? $params['posts_per_page'] : -1, // 每頁顯示文章數量
            'paged'          => isset($params['page']) ? $params['page'] : 1,                      // 當前頁碼
            'orderby'        => isset($params['orderby']) ? $params['orderby'] : 'id',             // 排序方式
            'order'          => isset($params['order']) ? $params['order'] : 'desc',               // 排序順序（DESC: 新到舊，ASC: 舊到新）
        ];
        // 如果有meta_query 參數，則加入查詢條件
        if (isset($params['meta_query'])) {
            $meta_query         = Base::sanitize_meta_query($params['meta_query']);
            $args['meta_query'] = $meta_query;
        }
        //加入insurer_id條件
        if (isset($params['insurer_id'])) {
            $args['meta_query'][] = [
                'key'     => 'insurer_id',
                'value'   => $params['insurer_id'],
                'compare' => '=',
            ];
        }
        // error_log(print_r($args, true));
        //主查詢
        $query = new \WP_Query($args);

        //取得insurer 資料
        if ($query->have_posts()) {
            $insurer_data = get_post($params['insurer_id']);
        }
        // 取得Terms id
        $terms_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $terms_ids[] = get_post_meta(get_the_ID(), 'term_id', true);
            }
        }
        $terms_ids = array_values(array_unique($terms_ids));
        // 取得Terms 資料
        $terms_map = [];
        if ($terms_ids) {
            $terms_data = get_posts([
                'post_type'              => 'terms',
                'post__in'               => $terms_ids,
                'update_post_meta_cache' => true,
                'fields'                 => 'all',
                'numberposts'            => -1,
            ]);
            foreach ($terms_data as $term) {
                $terms_map[$term->ID] = $term;
            }
        }
        // 整合資料
        $posts_data = [];
        if ($query->have_posts()) {
                $all_premium = 0;
                $all_gross_premium = 0;
                $all_ECI_value = 0;
                $all_insurer_payment = 0;
                $all_total_premium = 0;
            while ($query->have_posts()) {
                $query->the_post();
                $the_note = $query->post;
                $term = $terms_map[get_post_meta(get_the_ID(), 'term_id', true)];
                $term_id = $term ? $term->ID : '';
                $premium =  'MOTOR'!=$term->post_title ?(float)get_post_meta(get_the_ID(), 'premium', true) : 0;
                $gross_premium = 'MOTOR'==$term->post_title ? (float)$this->get_gross_premium($the_note) : 0;
                $total_premium = (float)$this->get_total_premium($the_note);
                $insurer_payment = (float)$this->get_insurer_payment($the_note, $insurer_data);
                $ECI = maybe_unserialize(get_post_meta(get_the_ID(), 'extra_field', true));
                $ECI_value = $ECI['label']=='ECI' ?(float)$premium * (float)$ECI['value']/100 : 0;
                if($the_note->post_type == 'credit_notes'){
                    $premium = -$premium;
                    $gross_premium = -$gross_premium;
                    $total_premium = -$total_premium;
                    $insurer_payment = -$insurer_payment;
                }
                $all_premium += $premium;
                $all_gross_premium += $gross_premium;
                $all_ECI_value += $ECI_value;
                $all_insurer_payment += $insurer_payment;
                $all_total_premium += $total_premium;
                // 以term_id為key
                if (isset($posts_data[$term_id])) {
                    if('MOTOR'==$term->post_title){
                        $posts_data[$term_id]['Gross Prem. Motor'] += $gross_premium;
                        $posts_data[$term_id]['2% ECI'] += $ECI_value;
                        $posts_data[$term_id]['Clients Net'] += $total_premium;
                        $posts_data[$term_id]['Principal'] += $insurer_payment;
                        $posts_data[$term_id]['Net Prem'] += $total_premium;
                        $posts_data[$term_id]["Broker's Override"] += $total_premium - $insurer_payment;
                        $posts_data[$term_id]['Trans'] += 1;
                    }
                    else{
                        $posts_data[$term_id]['Gross Prem'] += $premium;
                        $posts_data[$term_id]['2% ECI'] += $ECI_value;
                        $posts_data[$term_id]['Clients Net'] += $total_premium;
                        $posts_data[$term_id]['Principal'] += $insurer_payment;
                        $posts_data[$term_id]['Net Prem'] += $total_premium;
                        $posts_data[$term_id]["Broker's Override"] += $total_premium - $insurer_payment;
                        $posts_data[$term_id]['Trans'] += 1;
                    }
                    // $posts_data[$term_id]['Gross Prem'] += $premium;
                    
                } else {
                    $posts_data[$term_id] = [
                        'Insurer Name' => $insurer_data ? $insurer_data->post_title : '',
                        'Class' => $term ? $term->post_title : '',
                        'Gross Prem' => $premium,
                        'Gross Prem. Motor' => $gross_premium,
                        '2% ECI' => $ECI_value,
                        'Clients Net' => $total_premium,
                        'Principal' => $insurer_payment,
                        'Net Prem'=> $total_premium,
                        'Brokerage'=>0,
                        'Sub-Broke'=>0,
                        "Broker's Override"=>$total_premium - $insurer_payment,
                        'Trans'=>1
                    ];
                }
            }
            $posts_data[] = [
                'Insurer Name' => '',
                'Class'            => 'Total',
                'Gross Prem'   => $all_premium,
                'Gross Prem. Motor' =>  $all_gross_premium,
                '2% ECI' => $all_ECI_value,
                'Clients Net' => $all_total_premium,
                'Principal' => $all_insurer_payment,
                'Net Prem' => $all_total_premium,
                'Brokerage' => 0,
                'Sub-Broke' => 0,
                "Broker's Override" => $all_total_premium - $all_insurer_payment,
                'Trans' => $query->found_posts,
            ];
            $posts_data = array_values($posts_data);
            foreach ($posts_data as $key => $value) {
                $posts_data[$key]['Gross Prem'] = number_format($value['Gross Prem'], 2, '.', ',');
                $posts_data[$key]['Gross Prem. Motor'] = number_format($value['Gross Prem. Motor'], 2, '.', ',');
                $posts_data[$key]['2% ECI'] = number_format($value['2% ECI'], 2, '.', ',');
                $posts_data[$key]['Clients Net'] = number_format($value['Clients Net'], 2, '.', ',');
                $posts_data[$key]['Principal'] = number_format($value['Principal'], 2, '.', ',');
                $posts_data[$key]['Net Prem'] = number_format($value['Net Prem'], 2, '.', ',');
                $posts_data[$key]['Brokerage'] = number_format($value['Brokerage'], 2, '.', ',');
                $posts_data[$key]['Sub-Broke'] = number_format($value['Sub-Broke'], 2, '.', ',');
                $posts_data[$key]["Broker's Override"] = number_format($value["Broker's Override"], 2, '.', ',');
                $posts_data[$key]['Trans'] = number_format($value['Trans'], 2, '.', ',');
            }
        }
        $response = new \WP_REST_Response($posts_data);
        $total    = count($posts_data);
        // Set pagination in header.
        $response->header('X-WP-Total', $total);
        // $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }
    /**
     * get_insurer_payment Utils function
     *
     * @param $the_note
     * @param $insurer
     * @return float
     */
    public function get_insurer_payment($the_note, $insurer)
    {
        $insurer_fee_percent = floatval($the_note->insurer_fee_percent) ?? floatval($insurer->payment_rate) ?? 0;
        $premium             = floatval($the_note->premium) ?? 0;
        $motor_attr          = maybe_unserialize(get_post_meta($the_note->ID, 'motor_attr', true));
        $gross_premium       = $this->get_gross_premium($the_note);
        
        // 對稱處理，避免負數被系統性往上推
        $gross_premium       = round($gross_premium + 1e-10, 2,PHP_ROUND_HALF_UP);
        $template            = $the_note->template ?? '';
        if ($template == 'motor') {
            $mib               = floatval($motor_attr['mib']??0) ?? 0;
            $mib_value         = round($gross_premium * ($mib / 100), 2,PHP_ROUND_HALF_UP);
            $extra_field       = maybe_unserialize(get_post_meta($the_note->ID, 'extra_field', true));
            $extra_field_value = round($premium * (floatval($extra_field['value']??0) / 100), 2, PHP_ROUND_HALF_UP);
            $insurer_payment   = $mib_value + $extra_field_value + round($insurer_fee_percent * $gross_premium / 100+1e-10, 2,PHP_ROUND_HALF_UP);
        } else {
            $levy              = get_post_meta($the_note->ID, 'levy', true);
            $levy_value        = round($gross_premium * (floatval($levy??0) / 100), 2,PHP_ROUND_HALF_UP);
            $extra_field       = maybe_unserialize(get_post_meta($the_note->ID, 'extra_field', true));
            $extra_field_value = round($premium * (floatval($extra_field['value']??0) / 100), 2, PHP_ROUND_HALF_UP);
            $insurer_payment   = $levy_value + $extra_field_value + round($insurer_fee_percent * $gross_premium / 100+1e-10, 2,PHP_ROUND_HALF_UP);
        }
        return $insurer_payment;
    }
    /**
     * get_gross_premium Utils function
     *
     * @param $the_note
     * @return float
     */
    public function get_gross_premium($the_note)
    {
        $premium = floatval(get_post_meta($the_note->ID, 'premium', true)) ?? 0;
        $motor_attr = maybe_unserialize(get_post_meta($the_note->ID, 'motor_attr', true));
        $ls = floatval($motor_attr['ls']??0) ?? 0;
        $ncb = floatval($motor_attr['ncb']??0) ?? 0;
        $gross_premium = $premium * (1 + $ls / 100) * (1 + $ncb / 100);
        return round($gross_premium, 2,PHP_ROUND_HALF_UP);
    }
    /**
     * get_total_premium Utils function
     *
     * @param $the_note
     * @return float
     */
    public function get_total_premium($the_note)
    {   
        $template = get_post_meta($the_note->ID, 'template', true);
        if ($template == 'motor') {
            $gross_premium = $this->get_gross_premium($the_note);
            $motor_attr = maybe_unserialize(get_post_meta($the_note->ID, 'motor_attr', true));
            $mib = floatval($motor_attr['mib']??0);
            $less = floatval(get_post_meta($the_note->ID, 'less', true));
            $extra_field = maybe_unserialize(get_post_meta($the_note->ID, 'extra_field', true));
            $extra_field_value = floatval($extra_field['value']??0);
            $total_premium = $gross_premium * (1 + ($mib + $extra_field_value) / 100) + $less + 1e-10;
        } else {
            $premium = floatval(get_post_meta($the_note->ID, 'premium', true));
            $levy = floatval(get_post_meta($the_note->ID, 'levy', true));
            $less = floatval(get_post_meta($the_note->ID, 'less', true));
            $extra_field = maybe_unserialize(get_post_meta($the_note->ID, 'extra_field', true));
            $extra_field_value = floatval($extra_field['value']??0);
            $total_premium = $premium * (1 + ($levy + $extra_field_value) / 100) + $less + 1e-10;
        }
        return round($total_premium, 2,PHP_ROUND_HALF_UP);
    }

    /**
     * 計算 Account Receivable 的 This Period Debit（期間內所開的 Debit Note 總金額）
     *
     * @param string|null $start_date 開始日期
     * @param string|null $end_date 結束日期
     * @param \DateTimeZone $wp_timezone WordPress 時區
     * @return float
     */
    private function calculate_account_receivable_debit($start_date, $end_date, $wp_timezone)
    {
        $total_debit = 0;
        
        if (!$start_date || !$end_date) {
            return $total_debit;
        }
        
        // 查詢期間內的 Debit Notes
        $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
        $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
        $start_timestamp = $start_datetime->getTimestamp();
        $end_timestamp = $end_datetime->getTimestamp();
        
        $debit_notes_args = [
            'post_type' => 'debit_notes',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'date',
                    'value' => [$start_timestamp, $end_timestamp],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ]
        ];
        
        $debit_notes_query = new \WP_Query($debit_notes_args);
        if ($debit_notes_query->have_posts()) {
            while ($debit_notes_query->have_posts()) {
                $debit_notes_query->the_post();
                $the_note = $debit_notes_query->post;
                $total_premium = $this->get_total_premium($the_note);
                $total_debit += $total_premium;
            }
        }
        wp_reset_postdata();
        
        return round($total_debit, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算 Credit Note Premium Total（期間內所開的 Credit Note 總金額）
     *
     * @param string|null $start_date 開始日期
     * @param string|null $end_date 結束日期
     * @param \DateTimeZone $wp_timezone WordPress 時區
     * @return float
     */
    private function calculate_credit_note_premium_total($start_date, $end_date, $wp_timezone)
    {
        $total_premium_sum = 0;

        if (!$start_date || !$end_date) {
            return $total_premium_sum;
        }

        // 查詢期間內的 Credit Notes
        $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
        $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
        $start_timestamp = $start_datetime->getTimestamp();
        $end_timestamp = $end_datetime->getTimestamp();

        $credit_notes_args = [
            'post_type' => 'credit_notes',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'date',
                    'value' => [$start_timestamp, $end_timestamp],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ]
        ];

        $credit_notes_query = new \WP_Query($credit_notes_args);
        if ($credit_notes_query->have_posts()) {
            while ($credit_notes_query->have_posts()) {
                $credit_notes_query->the_post();
                $the_note = $credit_notes_query->post;
                $total_premium_sum += $this->get_total_premium($the_note);
            }
        }
        wp_reset_postdata();

        return round($total_premium_sum, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算 Other Earning 模組（獨立 CPT other_earnings）的期間總金額
     *
     * 注意：與 expense_class 為 other-earning-rebate 的支出分類不是同一回事，
     * 那個分類已排除在 Trial Balance 之外（見 TRIAL_BALANCE_NON_EXPENSE_SLUGS）。
     * 日期取 date 而非 payment_date，與 Trial Balance 其他科目一致。
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string|null $bank_name 指定銀行（payment_receiver_account），null 表示不限
     * @return float
     */
    private function calculate_other_earnings_module_total($start_date, $end_date, $bank_name = null)
    {
        $args = [
            'post_type' => 'other_earnings',
            'posts_per_page' => -1,
        ];

        if ($bank_name) {
            $args['meta_query'] = [
                [
                    'key' => 'payment_receiver_account',
                    'value' => $bank_name,
                    'compare' => '=',
                ],
            ];
        }

        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();

            $args['meta_query'][] = [
                'key' => 'date',
                'value' => [$start_timestamp, $end_timestamp],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ];
        }

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $total += floatval(get_post_meta(get_the_ID(), 'amount', true));
            }
        }
        wp_reset_postdata();

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Get profit and loss analysis callback
     *
     * 數字全部取自 Trial Balance 的本期：Premium Received = No.25、Premium Paid = No.26、
     * Rebate Received = No.27、Total Expenses = No.29–55 加總（客戶「終極版」Excel 的 Profit & Loss 工作表）。
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_profit_and_loss_analysis_callback($request)
    { // phpcs:ignore
        [$start_date, $end_date] = $this->get_report_date_range($request);

        // 損益科目沒有期初，本期淨額就是 Trial Balance 的期末
        $balances = [];
        $movements = $this->calculate_trial_balance_movements($start_date, $end_date, wp_timezone());
        foreach (self::TRIAL_BALANCE_ACCOUNTS as [, , $account_name, $category]) {
            $debit = (float) ($movements[$account_name]['debit'] ?? 0);
            $credit = (float) ($movements[$account_name]['credit'] ?? 0);
            $balances[$account_name] = 'EXPENSE' === $category ? $debit - $credit : $credit - $debit;
        }
        $profit_and_loss = $this->calculate_profit_and_loss($balances);

        $row = static function (string $account, $amount, string $category): array {
            return [
                'Account' => $account,
                'Current_Period' => $amount,
                'Category' => $category,
            ];
        };
        $empty_row = $row('', '', 'EMPTY');

        $data = [
            $row('Profit and Loss Statement', '', 'HEADER'),
            $row('As at ' . $this->format_report_end_date($end_date), '', 'HEADER'),
            $row('', 'THIS PERIOD ' . $this->format_report_period($start_date, $end_date), 'COLUMN_HEADER'),
            $empty_row,
            $row('Income:', '', 'SECTION'),
            $row('Premium Received', $profit_and_loss['premium_received'], 'ITEM'),
            $row('Total Income', $profit_and_loss['premium_received'], 'TOTAL'),
            $empty_row,
            $row('LESS:', '', 'SECTION'),
            $row('Premium Paid - General', $profit_and_loss['premium_paid'], 'ITEM'),
            $row('', $profit_and_loss['premium_paid'], 'SUBTOTAL'),
            $empty_row,
            $row('Other Earning :', '', 'SECTION'),
            $row('Rebate Received', $profit_and_loss['rebate_received'], 'ITEM'),
            $row('', $profit_and_loss['rebate_received'], 'SUBTOTAL'),
            $empty_row,
            $row('Gross Profit :', $profit_and_loss['gross_profit'], 'TOTAL'),
            $empty_row,
            $row('Admin & General Expenses', '', 'SECTION'),
            $row('Total Expenses:', $profit_and_loss['total_expenses'], 'TOTAL'),
            $empty_row,
            $row('NET PROFIT FOR THE YEAR', $profit_and_loss['net_profit'], 'FINAL_TOTAL'),
        ];

        return $this->report_response(['data' => $data]);
    }

    /**
     * 由 Trial Balance 損益科目的淨額算出 Profit & Loss 各項
     *
     * @param array<string, float> $balances key 為 Account Name，收入取「貸 − 借」、費用取「借 − 貸」
     * @return array{premium_received: float, premium_paid: float, rebate_received: float, gross_profit: float, total_expenses: float, net_profit: float}
     */
    private function calculate_profit_and_loss(array $balances): array
    {
        $premium_received = $balances['Premium Received'] ?? 0.0;
        $premium_paid = $balances['Premium Paid - General'] ?? 0.0;
        $rebate_received = $balances['Rebate-Received / Others'] ?? 0.0;

        // Total Expenses = No.29–55，也就是 Premium Paid 以外的所有費用科目
        $total_expenses = 0.0;
        foreach (self::TRIAL_BALANCE_ACCOUNTS as [, , $account_name, $category]) {
            if ('EXPENSE' === $category && 'Premium Paid - General' !== $account_name) {
                $total_expenses += $balances[$account_name] ?? 0.0;
            }
        }

        $gross_profit = $premium_received - $premium_paid + $rebate_received;

        // + 0.0 是為了把 -0.0 轉成 0.0，避免匯出時出現 -0.00
        return array_map(
            static fn (float $value): float => round($value, 2, PHP_ROUND_HALF_UP) + 0.0,
            [
                'premium_received' => $premium_received,
                'premium_paid' => $premium_paid,
                'rebate_received' => $rebate_received,
                'gross_profit' => $gross_profit,
                'total_expenses' => $total_expenses,
                'net_profit' => $gross_profit - $total_expenses,
            ]
        );
    }

    /**
     * 報表標題的「As at」日期（d/m/y），沒有結束日時用今天
     *
     * @param string|null $end_date Y-m-d
     * @return string
     */
    private function format_report_end_date($end_date): string
    {
        return (new \DateTime($end_date ?? 'now', wp_timezone()))->format('d/m/y');
    }

    /**
     * 報表期間標籤（d/m/Y - d/m/Y），不限日期時為 All Dates
     *
     * @param string|null $start_date Y-m-d
     * @param string|null $end_date   Y-m-d
     * @return string
     */
    private function format_report_period($start_date, $end_date): string
    {
        if (!$start_date || !$end_date) {
            return 'All Dates';
        }
        $wp_timezone = wp_timezone();
        return (new \DateTime($start_date, $wp_timezone))->format('d/m/Y') . ' - ' . (new \DateTime($end_date, $wp_timezone))->format('d/m/Y');
    }

    /**
     * 計算 Receipt 總金額
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @return float
     */
    private function calculate_receipt_total($start_date, $end_date)
    {
        // 查詢 receipts 文章類型
        $args = [
            'post_type' => 'receipts',
            'posts_per_page' => -1
        ];
        
        // 只有在提供日期參數時才添加日期篩選
        if ($start_date && $end_date) {
            // 取得 WordPress 時區
            $wp_timezone = wp_timezone();
            
            // 建立開始和結束日期時間物件
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            
            // 轉換為 UTC 時間戳記以進行資料庫查詢
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();
            
            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'key' => 'date',
                    'value' => [$start_timestamp, $end_timestamp],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ];
        }

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $amount = floatval(get_post_meta(get_the_ID(), 'premium', true));
                $total += $amount;
            }
        }
        wp_reset_postdata();

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算指定 expense_class post_name 的 Expenses 總金額
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string $term_post_name expense_class 的 post_name（例如 'insurer-payment-msig'）
     * @return float
     */
    private function calculate_expenses_total_by_term_post_name($start_date, $end_date, $term_post_name)
    {
        // 查詢 expenses 文章類型
        $args = [
            'post_type' => 'expenses',
            'posts_per_page' => -1
        ];
        
        // 只有在提供日期參數時才添加日期篩選
        if ($start_date && $end_date) {
            // 取得 WordPress 時區
            $wp_timezone = wp_timezone();
            
            // 建立開始和結束日期時間物件
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            
            // 轉換為 UTC 時間戳記以進行資料庫查詢
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();

            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'key' => 'date',
                    'value' => [$start_timestamp, $end_timestamp],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ];
        }

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            // 先收集所有的 term_id 和對應的金額
            $term_ids = [];
            $expenses_data = [];
            
            while ($query->have_posts()) {
                $query->the_post();
                $term_id = get_post_meta(get_the_ID(), 'term_id', true);
                $amount = floatval(get_post_meta(get_the_ID(), 'amount', true));
                
                if ($term_id) {
                    $term_ids[] = $term_id;
                    $expenses_data[] = [
                        'term_id' => $term_id,
                        'amount' => $amount
                    ];
                }
            }
            wp_reset_postdata();
            
            // 一次性取得所有相關的 terms
            if (!empty($term_ids)) {
                $terms_args = [
                    'post_type' => 'terms',
                    'post__in' => array_unique($term_ids),
                    'posts_per_page' => -1,
                    'meta_query' => [
                        [
                            'key' => 'taxonomy',
                            'value' => 'expense_class',
                            'compare' => '='
                        ]
                    ]
                ];
                
                $terms_query = new \WP_Query($terms_args);
                $terms_map = [];
                
                if ($terms_query->have_posts()) {
                    while ($terms_query->have_posts()) {
                        $terms_query->the_post();
                        $terms_map[get_the_ID()] = get_post_field('post_name', get_the_ID());
                    }
                    wp_reset_postdata();
                }
                
                // 計算總金額：只計算指定的 post_name
                foreach ($expenses_data as $expense) {
                    $term_id = $expense['term_id'];
                    $amount = $expense['amount'];
                    
                    if (isset($terms_map[$term_id])) {
                        $post_name = $terms_map[$term_id];
                        
                        // 檢查是否為目標 post_name
                        if ($post_name === $term_post_name) {
                            $total += $amount;
                        }
                    }
                }
            }
        }

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算指定 term 的 post_title 的 Expenses 總金額
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string $term_title expense_class 的 post_title（例如 'Salary – LCC'）
     * @return float
     */
    private function calculate_expenses_total_by_term_title($start_date, $end_date, $term_title)
    {
        $args = [
            'post_type' => 'expenses',
            'posts_per_page' => -1
        ];

        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();

            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'key' => 'date',
                    'value' => [$start_timestamp, $end_timestamp],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ];
        }

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            $term_ids = [];
            $expenses_data = [];

            while ($query->have_posts()) {
                $query->the_post();
                $term_id = get_post_meta(get_the_ID(), 'term_id', true);
                $amount = floatval(get_post_meta(get_the_ID(), 'amount', true));

                if ($term_id) {
                    $term_ids[] = $term_id;
                    $expenses_data[] = [
                        'term_id' => $term_id,
                        'amount' => $amount
                    ];
                }
            }
            wp_reset_postdata();

            if (!empty($term_ids)) {
                $terms_args = [
                    'post_type' => 'terms',
                    'post__in' => array_unique($term_ids),
                    'posts_per_page' => -1,
                    'meta_query' => [
                        [
                            'key' => 'taxonomy',
                            'value' => 'expense_class',
                            'compare' => '='
                        ]
                    ]
                ];

                $terms_query = new \WP_Query($terms_args);
                $terms_map = [];

                if ($terms_query->have_posts()) {
                    while ($terms_query->have_posts()) {
                        $terms_query->the_post();
                        $terms_map[get_the_ID()] = html_entity_decode(get_the_title(), ENT_QUOTES, 'UTF-8');
                    }
                    wp_reset_postdata();
                }

                foreach ($expenses_data as $expense) {
                    $term_id = $expense['term_id'];
                    $amount = $expense['amount'];

                    if (isset($terms_map[$term_id])) {
                        $current_term_title = $terms_map[$term_id];

                        if ($current_term_title === $term_title) {
                            $total += $amount;
                        }
                    }
                }
            }
        }

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算指定銀行的 Receipt 總金額（以 receipts.premium 為準）
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string $bank_name
     * @return float
     */
    private function calculate_receipt_total_by_bank($start_date, $end_date, $bank_name)
    {
        // 查詢 receipts 文章類型
        $args = [
            'post_type' => 'receipts',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'payment_receiver_account',
                    'value' => $bank_name,
                    'compare' => '=',
                ],
            ],
        ];

        // 只有在提供日期參數時才添加日期篩選
        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();

            $args['meta_query'][] = [
                'key' => 'date',
                'value' => [$start_timestamp, $end_timestamp],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ];
        }

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $amount = floatval(get_post_meta(get_the_ID(), 'premium', true));
                $total += $amount;
            }
        }
        wp_reset_postdata();

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算指定銀行的 Expenses 總金額（expenses.amount 且排除 Adjust Balance）
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string $bank_name
     * @return float
     */
    private function calculate_expenses_total_by_bank($start_date, $end_date, $bank_name)
    {
        $args = [
            'post_type' => 'expenses',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'payment_receiver_account',
                    'value' => $bank_name,
                    'compare' => '=',
                ],
                // 排除 Adjust Balance（相容舊資料：is_adjust_balance 不存在也算一般 Expenses）
                [
                    'relation' => 'OR',
                    [
                        'key' => 'is_adjust_balance',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => 'is_adjust_balance',
                        'value' => 1,
                        'compare' => '!=',
                        'type' => 'NUMERIC',
                    ],
                ],
            ],
        ];

        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);
            $start_timestamp = $start_datetime->getTimestamp();
            $end_timestamp = $end_datetime->getTimestamp();

            $args['meta_query'][] = [
                'key' => 'date',
                'value' => [$start_timestamp, $end_timestamp],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ];
        }

        // Other Earning – Rebate 之類記在 expenses 但其實是收入的分類，不算銀行支出
        $excluded_term_ids = $this->get_expense_class_term_ids_by_slugs(self::TRIAL_BALANCE_NON_EXPENSE_SLUGS);

        $query = new \WP_Query($args);
        $total = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                if ($excluded_term_ids && in_array((int) get_post_meta(get_the_ID(), 'term_id', true), $excluded_term_ids, true)) {
                    continue;
                }
                $amount = floatval(get_post_meta(get_the_ID(), 'amount', true));
                $total += $amount;
            }
        }
        wp_reset_postdata();

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 計算指定 insurer（通過 post_name）的 Insurer Payment 總金額
     * 從所有 receipts 中判斷是 debitNote/creditNote/renewal，然後進行 get_insurer_payment 之後將金額加總
     *
     * 期間依「對應單據的日期」判斷，而不是 receipt 自己的收款日
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @param string $insurer_post_name insurer 的 post_name（例如 'msig-insurance-hong-kong-ltd'）
     * @return float
     */
    private function calculate_insurer_payment_total_by_post_name($start_date, $end_date, $insurer_post_name)
    {
        $insurers = get_posts([
            'post_type' => 'insurers',
            'posts_per_page' => 1,
            'name' => $insurer_post_name, // 使用 post_name 查找
        ]);
        if (empty($insurers)) {
            return 0;
        }
        $insurer = $insurers[0];

        $start_timestamp = null;
        $end_timestamp = null;
        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_timestamp = (new \DateTime($start_date . ' 00:00:00', $wp_timezone))->getTimestamp();
            $end_timestamp = (new \DateTime($end_date . ' 23:59:59', $wp_timezone))->getTimestamp();
        }

        $total = 0;
        foreach ($this->get_receipt_source_notes() as ['note' => $the_note, 'is_credit_note' => $is_credit_note, 'timestamp' => $note_timestamp]) {
            if (null !== $start_timestamp && ($note_timestamp < $start_timestamp || $note_timestamp > $end_timestamp)) {
                continue;
            }
            if (get_post_meta($the_note->ID, 'insurer_id', true) != $insurer->ID) {
                continue;
            }

            $insurer_payment = $this->get_insurer_payment($the_note, $insurer);
            // 如果是 creditNote 則減去，否則加上
            $total += $is_credit_note ? -$insurer_payment : $insurer_payment;
        }

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * 取得每張 receipt 對應的單據與單據日期（同一次請求內只查一次）
     *
     * 對應單據的優先順序：creditNote > renewal > debitNote，與 Insurer Payment 畫面相同。
     * 單據沒有 date post meta 時（例如開收據時自動補建的空 debit note）改用 post 發佈日，
     * 與 Api\Receipts::get_source_note_date_meta_query() 的篩選一致。
     *
     * @return array<int, array{note: \WP_Post, is_credit_note: bool, timestamp: int}>
     */
    private function get_receipt_source_notes(): array
    {
        if (null !== $this->receipt_source_notes_cache) {
            return $this->receipt_source_notes_cache;
        }

        $receipt_ids = get_posts([
            'post_type' => 'receipts',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $relations = [
            'created_from_credit_note_id' => 'credit_notes',
            'created_from_renewal_id' => 'renewals',
            'debit_note_id' => 'debit_notes',
        ];

        // 先收集所有需要的 note IDs，再依類型一次查出來
        $receipt_note_ids = [];
        $note_ids_by_post_type = array_fill_keys($relations, []);
        foreach ($receipt_ids as $receipt_id) {
            foreach ($relations as $meta_key => $post_type) {
                $note_id = (int) get_post_meta($receipt_id, $meta_key, true);
                $receipt_note_ids[$receipt_id][$post_type] = $note_id;
                if ($note_id) {
                    $note_ids_by_post_type[$post_type][] = $note_id;
                }
            }
        }

        $notes_by_post_type = [];
        foreach ($note_ids_by_post_type as $post_type => $note_ids) {
            $notes_by_post_type[$post_type] = [];
            if (empty($note_ids)) {
                continue;
            }
            $notes = get_posts([
                'post_type' => $post_type,
                'post__in' => array_values(array_unique($note_ids)),
                'posts_per_page' => -1,
                'no_found_rows' => true,
            ]);
            foreach ($notes as $note) {
                $notes_by_post_type[$post_type][$note->ID] = $note;
            }
        }

        $source_notes = [];
        foreach ($receipt_note_ids as $note_ids) {
            foreach ($relations as $post_type) {
                $note_id = $note_ids[$post_type];
                if (!$note_id || !isset($notes_by_post_type[$post_type][$note_id])) {
                    continue;
                }
                $the_note = $notes_by_post_type[$post_type][$note_id];
                $note_date = get_post_meta($the_note->ID, 'date', true);
                $source_notes[] = [
                    'note' => $the_note,
                    'is_credit_note' => 'credit_notes' === $post_type,
                    'timestamp' => '' !== $note_date ? (int) $note_date : (int) get_post_timestamp($the_note),
                ];
                break;
            }
        }

        $this->receipt_source_notes_cache = $source_notes;
        return $source_notes;
    }

    /**
     * 正規化支出分類名稱，供 Trial Balance 對應表比對
     *
     * 系統裡部分分類名稱帶著從別處貼上的隱形字元（U+2060），連接號也有 en dash 與 hyphen 混用，
     * 這裡統一移除隱形字元、把 en/em dash 換成 hyphen、壓縮空白並轉小寫。
     *
     * @param string $name 分類名稱
     * @return string
     */
    private function normalize_expense_class_name(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $name = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $name) ?? $name;
        $name = str_replace(["\u{2013}", "\u{2014}"], '-', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return strtolower(trim($name));
    }

    /**
     * 計算 Trial Balance 各費用科目的 This Period Debit
     *
     * 依 TRIAL_BALANCE_EXPENSE_MAP 把期間內的 Expenses 按分類名稱累加到科目，
     * 取代原本「每個科目各查一次、只認一個 slug」的做法。
     * 保險公司付款分類已由 A/C Payable 各列計算，這裡略過；
     * 其餘對不到科目的分類只記 log、不歸入任何一列，讓合計借貸不平衡成為看得見的訊號，
     * 而不是悄悄併進 Misc。
     *
     * @param string|null $start_date
     * @param string|null $end_date
     * @return array<string, float> key 為 Trial Balance 的 Account Name，涵蓋所有費用科目
     */
    private function calculate_expenses_totals_by_trial_balance_item($start_date, $end_date): array
    {
        $item_by_name = [];
        foreach (self::TRIAL_BALANCE_EXPENSE_MAP as $category_name => $item) {
            $item_by_name[$this->normalize_expense_class_name($category_name)] = $item;
        }

        $items = array_unique(array_merge(array_values(self::TRIAL_BALANCE_EXPENSE_MAP), [self::TRIAL_BALANCE_EXPENSE_OTHERS_ITEM]));
        $totals = array_fill_keys($items, 0.0);

        $args = [
            'post_type' => 'expenses',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        if ($start_date && $end_date) {
            $wp_timezone = wp_timezone();
            $start_datetime = new \DateTime($start_date . ' 00:00:00', $wp_timezone);
            $end_datetime = new \DateTime($end_date . ' 23:59:59', $wp_timezone);

            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'key' => 'date',
                    'value' => [$start_datetime->getTimestamp(), $end_datetime->getTimestamp()],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ];
        }

        // 依分類加總金額；Adjust Balance 不屬於任何費用科目
        $amount_by_term = [];
        foreach ((new \WP_Query($args))->posts as $expense_id) {
            if (filter_var(get_post_meta($expense_id, 'is_adjust_balance', true), FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $term_id = (int) get_post_meta($expense_id, 'term_id', true);
            if (!$term_id) {
                continue;
            }
            $amount_by_term[$term_id] = ($amount_by_term[$term_id] ?? 0.0) + floatval(get_post_meta($expense_id, 'amount', true));
        }

        if (empty($amount_by_term)) {
            return $totals;
        }

        $term_ids = (new \WP_Query([
            'post_type' => 'terms',
            'post__in' => array_keys($amount_by_term),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'taxonomy',
                    'value' => 'expense_class',
                    'compare' => '='
                ]
            ]
        ]))->posts;
        // 統一成 int，才能與 $amount_by_term 的 key 做嚴格比對
        $term_ids = array_map('intval', $term_ids);

        $unmapped = [];
        foreach ($amount_by_term as $term_id => $amount) {
            if (!in_array($term_id, $term_ids, true)) {
                $unmapped['term_id ' . $term_id] = $amount;
                continue;
            }
            $slug = get_post_field('post_name', $term_id);
            // 保險公司付款已由 A/C Payable 各列計算；非支出分類刻意不進任何費用科目
            if (in_array($slug, self::TRIAL_BALANCE_INSURER_PAYMENT_SLUGS, true)
                || in_array($slug, self::TRIAL_BALANCE_NON_EXPENSE_SLUGS, true)
            ) {
                continue;
            }

            $category_name = (string) get_post_field('post_title', $term_id);
            $normalized = $this->normalize_expense_class_name($category_name);

            if (isset($item_by_name[$normalized])) {
                $totals[$item_by_name[$normalized]] += $amount;
            } elseif (preg_match('/^others \d+$/', $normalized)) {
                $totals[self::TRIAL_BALANCE_EXPENSE_OTHERS_ITEM] += $amount;
            } else {
                $unmapped[$category_name] = $amount;
            }
        }

        if (!empty($unmapped)) {
            error_log('Trial Balance - 對不到科目的支出分類（未計入任何費用列）: ' . wp_json_encode($unmapped, JSON_UNESCAPED_UNICODE));
        }

        foreach ($totals as $item => $total) {
            $totals[$item] = round($total, 2, PHP_ROUND_HALF_UP);
        }

        return $totals;
    }

    /**
     * Trial Balance 期初常數的結帳日（Y-m-d）
     *
     * 期初餘額是截至這一天的結帳後數字，寫死在 TRIAL_BALANCE_ACCOUNTS；
     * 報表起始日之前的發生額從隔天開始滾算。
     */
    private const TRIAL_BALANCE_BASE_DATE = '2025-03-31';

    /**
     * Trial Balance 的 55 個科目，依客戶「終極版20260930-Trial Balance to RAN.xlsx」
     *
     * 每列為 [No., Attribute, Account Name, Category, 期初借方, 期初貸方]，期初為截至 TRIAL_BALANCE_BASE_DATE 的結帳後餘額。
     * Category 決定期末放哪一側：ASSET / CONTRA_ASSET / EXPENSE 屬借方性質，其餘屬貸方性質。
     * 損益科目（REVENUE / EXPENSE）期初為空，報表起始日前的發生額一律結轉進 Retained Profit。
     */
    private const TRIAL_BALANCE_ACCOUNTS = [
        [1, 'Fixed Asset', 'Motor car', 'ASSET', 195370.00, null],
        [2, 'Fixed Asset', 'Furniture & Fixture', 'ASSET', 356600.00, null],
        [3, 'Fixed Asset', 'Acc.depreciation - Motor Car', 'CONTRA_ASSET', -195370.00, null],
        [4, 'Fixed Asset', 'Acc. Depreciation - F&F', 'CONTRA_ASSET', -344950.00, null],
        [5, 'Fixed Asset', 'Leasehold Improvement', 'ASSET', 121300.00, null],
        [6, 'Fixed Asset', 'Acc. Depreciation - LH1', 'CONTRA_ASSET', -121300.00, null],
        [7, 'Current Asset', 'Utiliity & Other Deposit', 'ASSET', 400.00, null],
        [8, 'Current Asset', 'Account Receivable', 'ASSET', 29546.00, null],
        [9, 'Cash at Bank & On Hold', 'SOC - Current', 'ASSET', 417503.19, null],
        [10, 'Cash at Bank & On Hold', 'KP1 - Current', 'ASSET', 20722.54, null],
        [11, 'Cash at Bank & On Hold', 'SOS-Call', 'ASSET', 2470.01, null],
        [12, 'Cash at Bank & On Hold', 'Cash on Hold', 'ASSET', 1519.92, null],
        [13, 'Current Asset', 'Li Tsun Sun - A/C', 'ASSET', 1565787.44, null],
        [14, 'Current Asset', 'Lai Yuen Chun - A/C', 'ASSET', 1420032.16, null],
        [15, 'Current Asset', 'Prepaid Expenses', 'ASSET', 5000.00, null],
        [16, 'Current Liabilities', 'A/C Payable', 'LIABILITY', null, 12176.25],
        [17, 'Current Liabilities', 'A/C Payable - MSIG', 'LIABILITY', null, 232538.10],
        [18, 'Current Liabilities', 'A/C Payable - Tokio Marine', 'LIABILITY', null, 25403.88],
        [19, 'Current Liabilities', 'A/C Payable - CMB Wing Lung', 'LIABILITY', null, 25985.04],
        [20, 'Current Liabilities', 'A/C Payable - China Taiping', 'LIABILITY', null, 2334.65],
        [21, 'Current Liabilities', 'Creditor - Agent', 'LIABILITY', null, 11398.33],
        [22, 'Current Liabilities', 'Accrual Expenses', 'LIABILITY', null, 9100.00],
        [23, 'Capital', 'Share Capital', 'EQUITY', null, 2.00],
        [24, 'Capital', 'Retained Profit', 'EQUITY', null, 3155693.01],
        [25, 'Income', 'Premium Received', 'REVENUE', null, null],
        [26, 'Less', 'Premium Paid - General', 'EXPENSE', null, null],
        [27, 'Other Earning', 'Rebate-Received / Others', 'REVENUE', null, null],
        [28, 'Income', 'Other Income', 'REVENUE', null, null],
        [29, 'Selling Expenses', 'Entertainment', 'EXPENSE', null, null],
        [30, 'Admin & General Expenses', 'Salary - Li Chung Chai', 'EXPENSE', null, null],
        [31, 'Admin & General Expenses', 'Salary - Lai Yuen Chun', 'EXPENSE', null, null],
        [32, 'Admin & General Expenses', 'Director Remuneration - Li Tsun Sun', 'EXPENSE', null, null],
        [33, 'Admin & General Expenses', 'Printing & Stationery', 'EXPENSE', null, null],
        [34, 'Admin & General Expenses', 'Rent & Rates', 'EXPENSE', null, null],
        [35, 'Admin & General Expenses', 'Electricity, Water Fee & Gas', 'EXPENSE', null, null],
        [36, 'Admin & General Expenses', 'Telephone Fax & Internet Fee', 'EXPENSE', null, null],
        [37, 'Admin & General Expenses', 'Insurance', 'EXPENSE', null, null],
        [38, 'Admin & General Expenses', 'Management Fee', 'EXPENSE', null, null],
        [39, 'Admin & General Expenses', 'Stamp & Postage', 'EXPENSE', null, null],
        [40, 'Admin & General Expenses', 'Repairs & Maintenance', 'EXPENSE', null, null],
        [41, 'Admin & General Expenses', 'Business Registration', 'EXPENSE', null, null],
        [42, 'Admin & General Expenses', 'Bank Charges', 'EXPENSE', null, null],
        [43, 'Admin & General Expenses', 'Sundry Expenses', 'EXPENSE', null, null],
        [44, 'Admin & General Expenses', 'Study Allowance', 'EXPENSE', null, null],
        [45, 'Admin & General Expenses', 'Travel Expenses', 'EXPENSE', null, null],
        [46, 'Admin & General Expenses', 'Bonus', 'EXPENSE', null, null],
        [47, 'Admin & General Expenses', 'Lucky Money', 'EXPENSE', null, null],
        [48, 'Admin & General Expenses', 'Medical Expenese', 'EXPENSE', null, null],
        [49, 'Admin & General Expenses', 'MPF', 'EXPENSE', null, null],
        [50, 'Admin & General Expenses', 'Audit Fee', 'EXPENSE', null, null],
        [51, 'Admin & General Expenses', 'New Computer System', 'EXPENSE', null, null],
        [52, 'Admin & General Expenses', 'Tax', 'EXPENSE', null, null],
        [53, 'Admin & General Expenses', 'Gift', 'EXPENSE', null, null],
        [54, 'Admin & General Expenses', 'Loan to Director', 'EXPENSE', null, null],
        [55, 'Admin & General Expenses', 'Misc', 'EXPENSE', null, null],
    ];

    /**
     * Balance Sheet 上代表本期淨利的那一行
     */
    private const BALANCE_SHEET_PROFIT_AND_LOSS_LINE = 'Profit & Loss A/C';

    /**
     * Balance Sheet 的區塊，依客戶「終極版」Excel 的 BALANCE SHEET 工作表
     *
     * 每區為 [標題, 合計列名稱, Trial Balance 的 Account Name, 是否為資產]。
     */
    private const BALANCE_SHEET_SECTIONS = [
        ['FIXED ASSET', 'Total Fixed Asset :', [
            'Motor car',
            'Furniture & Fixture',
            'Acc.depreciation - Motor Car',
            'Acc. Depreciation - F&F',
            'Leasehold Improvement',
            'Acc. Depreciation - LH1',
        ], true],
        ['CURRENT ASSET', 'Sub - Total Current Asset :', [
            'Utiliity & Other Deposit',
            'Account Receivable',
            'Prepaid Expenses',
            'Li Tsun Sun - A/C',
            'Lai Yuen Chun - A/C',
        ], true],
        ['CASH AT BANK & ON HAND', 'Total Cash at Bank & on Hand :', [
            'SOC - Current',
            'KP1 - Current',
            'SOS-Call',
            'Cash on Hold',
        ], true],
        ['CURRENT LIABILITIES', 'Total Current Liabilities :', [
            'A/C Payable',
            'A/C Payable - MSIG',
            'A/C Payable - Tokio Marine',
            'A/C Payable - CMB Wing Lung',
            'A/C Payable - China Taiping',
            'Creditor - Agent',
            'Accrual Expenses',
        ], false],
        ['CAPITAL', 'Total Capital :', [
            'Share Capital',
            'Retained Profit',
            self::BALANCE_SHEET_PROFIT_AND_LOSS_LINE,
        ], false],
    ];

    /**
     * Trial Balance 的金額欄位，每組依序為借方、貸方
     */
    private const TRIAL_BALANCE_AMOUNT_GROUPS = [
        'Beginning Balance', // 截至 TRIAL_BALANCE_BASE_DATE 的期初常數
        'Beginning Period',  // TRIAL_BALANCE_BASE_DATE 隔天 ~ 報表起始日前一天的發生額
        'Opening Balance',   // 報表起始日的期初 = 前兩組相加，損益科目結轉進 Retained Profit
        'This Period',
        'Ending Balance',
    ];

    /**
     * 計算 Trial Balance 各科目在期間內的發生額
     *
     * 沒有列出的科目代表這段期間沒有資料來源，報表上留空。
     *
     * @param string|null   $start_date Y-m-d，與 $end_date 皆為 null 時不限日期
     * @param string|null   $end_date   Y-m-d
     * @param \DateTimeZone $wp_timezone
     * @return array<string, array{debit: float|string, credit: float|string}> key 為 Account Name，'' 表示該側沒有資料來源
     */
    private function calculate_trial_balance_movements($start_date, $end_date, $wp_timezone): array
    {
        // Account Receivable：借 = 期間內 Debit Note 總額；貸 = Receipt 總額 + Credit Note Premium Total
        $debit_note_total = $this->calculate_account_receivable_debit($start_date, $end_date, $wp_timezone);
        $credit_note_premium_total = $this->calculate_credit_note_premium_total($start_date, $end_date, $wp_timezone);
        $receivable_credit = round(
            $this->calculate_receipt_total($start_date, $end_date) + $credit_note_premium_total,
            2,
            PHP_ROUND_HALF_UP
        );

        // 銀行：借 = Income + Other Earning；貸 = Expenses（排除 Adjust Balance）
        // Other Earning 同時是 No.27 Rebate-Received / Others 的貸方，記在這裡才借貸相抵
        $bank_movement = function (string $bank_name) use ($start_date, $end_date): array {
            $income = $this->calculate_receipt_total_by_bank($start_date, $end_date, $bank_name);
            $other_earning = $this->calculate_other_earnings_module_total($start_date, $end_date, $bank_name);
            return [
                'debit' => round($income + $other_earning, 2, PHP_ROUND_HALF_UP),
                'credit' => $this->calculate_expenses_total_by_bank($start_date, $end_date, $bank_name),
            ];
        };

        // A/C Payable 各保險公司：借 = 付給保險公司的支出（insurer-payment-* 分類）；
        // 貸 = receipts 對應單據的 insurer payment，期間依單據日期判斷
        $insurers = [
            'A/C Payable - MSIG' => ['insurer-payment-msig', 'msig-insurance-hong-kong-ltd'],
            'A/C Payable - Tokio Marine' => ['insurer-payment-tokio', 'the-tokio-marine-fire-ins-co-hk-ltd'],
            'A/C Payable - CMB Wing Lung' => ['insurer-payment-cmb', 'cmb-wing-lung-insurance-co-ltd'],
            'A/C Payable - China Taiping' => ['insurer-payment-taiping', 'china-taiping-insurance-hk-co-ltd'],
        ];

        $movements = [
            'Account Receivable' => ['debit' => $debit_note_total, 'credit' => $receivable_credit],
            'SOC - Current' => $bank_movement('上海商業銀行'),
            'KP1 - Current' => $bank_movement('中國銀行'),
        ];

        $insurer_payment_sum = 0.0;
        foreach ($insurers as $account_name => [$expense_slug, $insurer_slug]) {
            $insurer_payment = $this->calculate_insurer_payment_total_by_post_name($start_date, $end_date, $insurer_slug);
            $insurer_payment_sum += $insurer_payment;
            $movements[$account_name] = [
                'debit' => $this->calculate_expenses_total_by_term_post_name($start_date, $end_date, $expense_slug),
                'credit' => $insurer_payment,
            ];
        }

        // Premium Received：借 = Credit Note Premium Total；貸 = Debit Note 總額
        $movements['Premium Received'] = ['debit' => $credit_note_premium_total, 'credit' => $debit_note_total];
        // Premium Paid - General：各保險公司 insurer payment 的總和
        $movements['Premium Paid - General'] = ['debit' => round($insurer_payment_sum, 2, PHP_ROUND_HALF_UP), 'credit' => ''];
        // Rebate-Received / Others：Other Earning 模組總額
        $movements['Rebate-Received / Others'] = [
            'debit' => '',
            'credit' => $this->calculate_other_earnings_module_total($start_date, $end_date),
        ];

        // Selling / Admin & General Expenses 各科目：依 TRIAL_BALANCE_EXPENSE_MAP 一次彙總
        foreach ($this->calculate_expenses_totals_by_trial_balance_item($start_date, $end_date) as $account_name => $total) {
            $movements[$account_name] = ['debit' => $total, 'credit' => ''];
        }

        return $movements;
    }

    /**
     * 取得報表的日期區間（Y-m-d）
     *
     * 只給其中一個日期時，起始日預設當月第一天、結束日預設今天；兩個都沒給表示不限日期。
     *
     * @param \WP_REST_Request $request Request.
     * @return array{0: string|null, 1: string|null}
     */
    private function get_report_date_range($request): array
    {
        $params = WP::sanitize_text_field_deep($request->get_query_params() ?? [], false);
        if (!isset($params['start_date']) && !isset($params['end_date'])) {
            return [null, null];
        }

        $current_wp_time = new \DateTime('now', wp_timezone());
        return [
            $params['start_date'] ?? $current_wp_time->format('Y-m-01'),
            $params['end_date'] ?? $current_wp_time->format('Y-m-d'),
        ];
    }

    /**
     * 包成報表 API 的回應
     *
     * @param array{data: array<int, array<string, mixed>>, notice?: string|null} $report
     * @return \WP_REST_Response
     */
    private function report_response(array $report): \WP_REST_Response
    {
        $response = new \WP_REST_Response([
            'data' => $report['data'],
            'total' => count($report['data']),
            'notice' => $report['notice'] ?? null,
            'success' => true
        ], 200);

        // 設定 JSON 編碼選項，避免斜線轉義
        $response->set_headers(['Content-Type' => 'application/json; charset=utf-8']);

        return $response;
    }

    /**
     * Get trial balance callback
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_trial_balance_callback($request)
    { // phpcs:ignore
        [$start_date, $end_date] = $this->get_report_date_range($request);
        return $this->report_response($this->build_trial_balance($start_date, $end_date));
    }

    /**
     * 產生 Trial Balance 報表資料
     *
     * 五組金額：期初常數（截至 TRIAL_BALANCE_BASE_DATE）→ 起始日前的發生額 → 起始日的期初 → 本期 → 期末。
     * 起始日早於 TRIAL_BALANCE_BASE_DATE 隔天時沒有東西可滾算，期初直接用常數，並回傳 notice 提示。
     *
     * @param string|null $start_date Y-m-d，與 $end_date 皆為 null 時不限日期
     * @param string|null $end_date   Y-m-d
     * @return array{data: array<int, array<string, mixed>>, notice: string|null}
     */
    private function build_trial_balance($start_date, $end_date): array
    {
        $wp_timezone = wp_timezone();

        $base_date_obj = new \DateTime(self::TRIAL_BALANCE_BASE_DATE, $wp_timezone);
        $rollforward_start_obj = (clone $base_date_obj)->modify('+1 day');

        if ($start_date && $end_date) {
            $start_date_obj = new \DateTime($start_date, $wp_timezone);
            $end_date_obj = new \DateTime($end_date, $wp_timezone);
            $period_label = $start_date_obj->format('d/m/y') . ' - ' . $end_date_obj->format('d/m/y');
            $opening_date_obj = (clone $start_date_obj)->modify('-1 day');
        } else {
            $period_label = 'All Dates';
            $opening_date_obj = null;
        }

        // 起始日前的發生額：TRIAL_BALANCE_BASE_DATE 隔天 ~ 起始日前一天，起始日不晚於隔天時沒有東西可滾算
        $can_rollforward = $opening_date_obj && $opening_date_obj >= $rollforward_start_obj;
        $beginning_period_movements = $can_rollforward
            ? $this->calculate_trial_balance_movements(
                $rollforward_start_obj->format('Y-m-d'),
                $opening_date_obj->format('Y-m-d'),
                $wp_timezone
            )
            : [];
        $this_period_movements = $this->calculate_trial_balance_movements($start_date, $end_date, $wp_timezone);

        $notice = null;
        if (!$opening_date_obj || $opening_date_obj < $base_date_obj) {
            $notice = sprintf(
                '期初餘額只適用於 %s 之後開始的區間，本報表的期初為截至 %s 的餘額',
                $rollforward_start_obj->format('d/m/Y'),
                $base_date_obj->format('d/m/Y')
            );
        }

        $base_label = $base_date_obj->format('d/m/Y');
        $group_titles = [
            'Beginning Balance' => 'BEGINNING BALANCE ' . $base_label,
            'Beginning Period' => $can_rollforward
                ? $rollforward_start_obj->format('d/m/Y') . ' - ' . $opening_date_obj->format('d/m/Y')
                : $rollforward_start_obj->format('d/m/Y') . ' - Beginning Period',
            'Opening Balance' => 'BEGINNING BALANCE ' . ($opening_date_obj && $opening_date_obj >= $base_date_obj ? $opening_date_obj->format('d/m/Y') : $base_label),
            'This Period' => 'THIS PERIOD ' . $period_label,
            'Ending Balance' => 'ENDING BALANCE',
        ];

        // Header Type 讓前端匯出決定標題列的排版：TITLE / SUBTITLE 置中、GROUP 為各組金額的標題、COLUMN 為欄名
        $make_header = static function (string $header_type, array $labels, array $amounts = []) {
            $row = [
                'No.' => $labels[0],
                'Attribute' => $labels[1],
                'Account Name' => $labels[2],
            ];
            foreach (self::TRIAL_BALANCE_AMOUNT_GROUPS as $group) {
                $row[$group . ' Debit'] = $amounts[$group][0] ?? '';
                $row[$group . ' Credit'] = $amounts[$group][1] ?? '';
            }
            $row['Category'] = 'HEADER';
            $row['Header Type'] = $header_type;
            return $row;
        };

        $data = [
            $make_header('TITLE', ['', '', 'TRIAL BALANCE']),
            $make_header('SUBTITLE', ['', '', 'For Period : ' . $period_label]),
            $make_header('GROUP', ['', '', ''], array_map(static fn ($title) => [$title, ''], $group_titles)),
            $make_header(
                'COLUMN',
                ['No.', 'Attribute', 'Account Name'],
                array_fill_keys(self::TRIAL_BALANCE_AMOUNT_GROUPS, ['Debit', 'Credit'])
            ),
        ];

        // + 0.0 是為了把 -0.0 轉成 0.0，避免匯出時出現 -0.00
        $round_amount = static function (float $value): float {
            return round($value, 2, PHP_ROUND_HALF_UP) + 0.0;
        };
        $to_amount = static function ($value): ?float {
            return ('' === $value || null === $value) ? null : (float) $value;
        };
        // 依科目性質把借貸淨額放到對應的一側；淨額為負時照放在性質那一側（例如累計折舊的借方為負數）
        $debit_nature_categories = ['ASSET', 'CONTRA_ASSET', 'EXPENSE'];
        $profit_and_loss_categories = ['REVENUE', 'EXPENSE'];
        $net_balance = static function (string $category, array $pairs) use ($debit_nature_categories, $round_amount): array {
            $has_value = false;
            $debit_sum = 0.0;
            $credit_sum = 0.0;
            foreach ($pairs as [$debit, $credit]) {
                $has_value = $has_value || null !== $debit || null !== $credit;
                $debit_sum += (float) $debit;
                $credit_sum += (float) $credit;
            }
            if (!$has_value) {
                return [null, null];
            }
            return in_array($category, $debit_nature_categories, true)
                ? [$round_amount($debit_sum - $credit_sum), null]
                : [null, $round_amount($credit_sum - $debit_sum)];
        };

        $account_rows = [];
        $retained_profit_closing = 0.0;
        foreach (self::TRIAL_BALANCE_ACCOUNTS as [$no, $attribute, $account_name, $category, $base_debit, $base_credit]) {
            $beginning_period = $beginning_period_movements[$account_name] ?? ['debit' => '', 'credit' => ''];
            $this_period = $this_period_movements[$account_name] ?? ['debit' => '', 'credit' => ''];

            $amounts = [
                'Beginning Balance' => [$base_debit, $base_credit],
                'Beginning Period' => [$to_amount($beginning_period['debit']), $to_amount($beginning_period['credit'])],
                'This Period' => [$to_amount($this_period['debit']), $to_amount($this_period['credit'])],
            ];

            // 損益科目在起始日前的淨額全部結轉進 Retained Profit，自己的期初留空
            if (in_array($category, $profit_and_loss_categories, true)) {
                $retained_profit_closing += (float) $amounts['Beginning Period'][1] - (float) $amounts['Beginning Period'][0];
                $amounts['Opening Balance'] = [null, null];
            } else {
                $amounts['Opening Balance'] = $net_balance($category, [$amounts['Beginning Balance'], $amounts['Beginning Period']]);
            }

            $account_rows[] = [
                'no' => $no,
                'attribute' => $attribute,
                'account_name' => $account_name,
                'category' => $category,
                'amounts' => $amounts,
            ];
        }

        $totals = [];
        foreach ($account_rows as $account_row) {
            $amounts = $account_row['amounts'];
            if ('Retained Profit' === $account_row['account_name']) {
                $amounts['Opening Balance'][1] = $round_amount((float) $amounts['Opening Balance'][1] + $retained_profit_closing);
            }
            $amounts['Ending Balance'] = $net_balance($account_row['category'], [$amounts['Opening Balance'], $amounts['This Period']]);

            $row = [
                'No.' => $account_row['no'],
                'Attribute' => $account_row['attribute'],
                'Account Name' => $account_row['account_name'],
            ];
            foreach (self::TRIAL_BALANCE_AMOUNT_GROUPS as $group) {
                foreach (['Debit' => 0, 'Credit' => 1] as $side => $index) {
                    $value = null === $amounts[$group][$index] ? null : $round_amount((float) $amounts[$group][$index]);
                    $row[$group . ' ' . $side] = $value;
                    $totals[$group . ' ' . $side] = ($totals[$group . ' ' . $side] ?? 0.0) + (float) $value;
                }
            }
            $row['Category'] = $account_row['category'];
            $data[] = $row;
        }

        $total_row = [
            'No.' => '',
            'Attribute' => '',
            'Account Name' => 'Total:',
        ];
        foreach ($totals as $field => $total) {
            $total_row[$field] = $round_amount($total);
        }
        $total_row['Category'] = 'TOTAL';
        $data[] = $total_row;

        return ['data' => $data, 'notice' => $notice];
    }

    /**
     * Get balance sheet callback
     *
     * 每一行都是 Trial Balance 的期末餘額，Profit & Loss A/C 為本期淨利，
     * 所以 Total Asset 恆等於 Total Current Liabilities + Total Capital。
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_balance_sheet_callback($request)
    { // phpcs:ignore
        [$start_date, $end_date] = $this->get_report_date_range($request);
        $trial_balance = $this->build_trial_balance($start_date, $end_date);

        $balances = [];
        foreach ($trial_balance['data'] as $trial_balance_row) {
            if (in_array($trial_balance_row['Category'], ['HEADER', 'TOTAL'], true)) {
                continue;
            }
            $balances[$trial_balance_row['Account Name']] = (float) ($trial_balance_row['Ending Balance Debit'] ?? $trial_balance_row['Ending Balance Credit']);
        }
        $balances[self::BALANCE_SHEET_PROFIT_AND_LOSS_LINE] = $this->calculate_profit_and_loss($balances)['net_profit'];

        $round_amount = static function (float $value): float {
            return round($value, 2, PHP_ROUND_HALF_UP) + 0.0;
        };
        $row = static function (string $account, string $category, array $amounts = []): array {
            return [
                'Account' => $account,
                'Amount' => $amounts['Amount'] ?? '',
                'Subtotal' => $amounts['Subtotal'] ?? '',
                'Total' => $amounts['Total'] ?? '',
                'Category' => $category,
            ];
        };

        $data = [
            $row('Balance Sheet', 'HEADER'),
            $row('As at ' . $this->format_report_end_date($end_date), 'HEADER'),
            $row('', 'EMPTY'),
        ];
        $total_asset = 0.0;
        foreach (self::BALANCE_SHEET_SECTIONS as [$title, $total_label, $account_names, $is_asset]) {
            $data[] = $row($title, 'SECTION');
            $section_total = 0.0;
            foreach ($account_names as $account_name) {
                $amount = $balances[$account_name] ?? 0.0;
                $section_total += $amount;
                $data[] = $row($account_name, 'ITEM', ['Amount' => $amount]);
            }
            // 資產各區的小計放 Subtotal 欄、再加總成 Total Asset；負債與資本直接放 Total 欄
            $data[] = $row($total_label, $is_asset ? 'SUBTOTAL' : 'TOTAL', [$is_asset ? 'Subtotal' : 'Total' => $round_amount($section_total)]);
            $data[] = $row('', 'EMPTY');

            if ($is_asset) {
                $total_asset += $section_total;
            }
            if ('CASH AT BANK & ON HAND' === $title) {
                $data[] = $row('Total Asset :', 'TOTAL', ['Total' => $round_amount($total_asset)]);
                $data[] = $row('', 'EMPTY');
            }
        }

        return $this->report_response(['data' => $data, 'notice' => $trial_balance['notice']]);
    }
}
