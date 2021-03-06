<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Torb\PDOWrapper;
use Psr\Container\ContainerInterface;
use SlimSession\Helper;

date_default_timezone_set('Asia/Tokyo');
define('TWIG_TEMPLATE', realpath(__DIR__).'/views');

$container = $app->getContainer();

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig(TWIG_TEMPLATE);

    $baseUrl = function (\Slim\Http\Request $request): string {
      if ($request->hasHeader('Host')) {
        return $request->getUri()->getScheme().'://'.$request->getHeaderLine('Host');
      }

      return $request->getUri()->getBaseUrl();
    };

    $view->addExtension(new \Slim\Views\TwigExtension($container['router'], $baseUrl($container['request'])));

    return $view;
};

$app->add(new \Slim\Middleware\Session([
    'name' => 'torb_session',
    'autorefresh' => true,
    'lifetime' => '1 week',
]));

$container['session'] = function (): Helper {
    return new Helper();
};

$login_required = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if (!$user) {
        return res_error($response, 'login_required', 401);
    }

    return $next($request, $response);
};

$fillin_user = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if ($user) {
        $this->view->offsetSet('user', $user);
    }

    return $next($request, $response);
};

$container['dbh'] = function (): PDOWrapper {
    $database = getenv('DB_DATABASE');
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASS');

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4;";
    $pdo = new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    return new PDOWrapper($pdo);
};

$app->get('/', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) {
        return sanitize_event($event);
    }, get_events($this->dbh));

    return $this->view->render($response, 'index.twig', [
        'events' => $events,
    ]);
})->add($fillin_user);

$app->get('/initialize', function (Request $request, Response $response): Response {
    exec('../../db/init.sh');

    return $response->withStatus(204);
});

$app->post('/api/users', function (Request $request, Response $response): Response {
    $nickname = $request->getParsedBodyParam('nickname');
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user_id = null;

    $this->dbh->beginTransaction();

    try {
        $duplicated = $this->dbh->select_one('SELECT * FROM users WHERE login_name = ?', $login_name);
        if ($duplicated) {
            $this->dbh->rollback();

            return res_error($response, 'duplicated', 409);
        }

        $this->dbh->execute('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', $login_name, $password, $nickname);
        $user_id = $this->dbh->last_insert_id();
        $this->dbh->commit();
    } catch (\Throwable $throwable) {
        $this->dbh->rollback();

        return res_error($response);
    }

    return $response->withJson([
        'id' => $user_id,
        'nickname' => $nickname,
    ], 201, JSON_NUMERIC_CHECK);
});

/**
 * @param ContainerInterface $app
 *
 * @return bool|array
 */
function get_login_user(ContainerInterface $app)
{
    /** @var Helper $session */
    $session = $app->session;
    $user_id = $session->get('user_id');
    if (null === $user_id) {
        return false;
    }

    $user = $app->dbh->select_row('SELECT id, nickname FROM users WHERE id = ?', $user_id);
    $user['id'] = (int) $user['id'];
    return $user;
}

$app->get('/api/users/{id}', function (Request $request, Response $response, array $args): Response {
    Analysis::time('users');
    $user = $this->dbh->select_row('SELECT id, nickname FROM users WHERE id = ?', $args['id']);
    $user['id'] = (int) $user['id'];
    if (!$user || $user['id'] !== get_login_user($this)['id']) {
        return res_error($response, 'forbidden', 403);
    }

    $recent_reservations = function (ContainerInterface $app) use ($user) {
        $recent_reservations = [];

        $rows = $app->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            //$event = get_event($app->dbh, $row['event_id']);
            $event = get_event_for_user($app->dbh, $row['event_id']);
            $price = $event['sheets'][$row['sheet_rank']]['price'];
            unset($event['sheets']);
            //unset($event['total']);
            //unset($event['remains']);

            $reservation = [
                'id' => $row['id'],
                'event' => $event,
                'sheet_rank' => $row['sheet_rank'],
                'sheet_num' => $row['sheet_num'],
                'price' => $price,
                'reserved_at' => (new \DateTime("{$row['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp(),
            ];

            if ($row['canceled_at']) {
                $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new DateTimeZone('UTC')))->getTimestamp();
            }

            array_push($recent_reservations, $reservation);
        }

        return $recent_reservations;
    };

    $user['recent_reservations'] = $recent_reservations($this);
    $user['total_price'] = $this->dbh->select_one('SELECT IFNULL(SUM(e.price + s.price), 0) FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', $user['id']);

    $recent_events = function (ContainerInterface $app) use ($user) {
        $recent_events = [];

        $rows = $app->dbh->select_all('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            //$event = get_event($app->dbh, $row['event_id']);
            $event = get_event_for_user_recent($app->dbh, $row['event_id']);
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
            array_push($recent_events, $event);
        }

        return $recent_events;
    };

    $user['recent_events'] = $recent_events($this);

    Analysis::timeEnd('users');
    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->post('/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user = $this->dbh->select_row('SELECT * FROM users WHERE login_name = ?', $login_name);
    $pass_hash = $this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$user || $pass_hash != $user['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    /** @var Helper $session */
    $session = $this->session;
    $session->set('user_id', $user['id']);

    $user = get_login_user($this);

    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
});

$app->post('/api/actions/logout', function (Request $request, Response $response): Response {
    /** @var Helper $session */
    $session = $this->session;
    $session->delete('user_id');

    return $response->withStatus(204);
})->add($login_required);

$app->get('/api/events', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) {
        return sanitize_event($event);
    }, get_events($this->dbh));

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
});

$app->get('/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'not_found', 404);
    }

    $event = sanitize_event($event);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
});

function get_events(PDOWrapper $dbh, ?callable $where = null): array
{
    if (null === $where) {
        $where = function (array $event) {
            return $event['public_fg'];
        };
    }

    $dbh->beginTransaction();

    $events = [];
    $event_ids = array_map(function (array $event) {
        return $event['id'];
    }, array_filter($dbh->select_all('SELECT * FROM events ORDER BY id ASC'), $where));

    foreach ($event_ids as $event_id) {
        // org
        //$event = get_event($dbh, $event_id);
        $event = get_event_summary($dbh, $event_id);

        //foreach (array_keys($event['sheets']) as $rank) {
        //    unset($event['sheets'][$rank]['detail']);
        //}

        array_push($events, $event);
    }

    $dbh->commit();

    return $events;
}


//TODO 開発
function get_event_summary($dbh, $event_id): array
{
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    // zero fill
    $event['total'] = 1000;
    $event['remains'] = 0;

    $remains_count = 0;
    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['total'] = get_total_sheets_count($rank);
        $event['sheets'][$rank]['remains'] = $event['sheets'][$rank]['total'] - get_sheets_reserve_count($dbh, $event_id, $rank);
        $event['sheets'][$rank]['price'] = $event['price'] + get_sheet_price($rank);
        $remains_count += $event['sheets'][$rank]['remains'];
    }
    $event['remains'] = $remains_count;

    return $event;
}

function get_total_sheets_count($rank) {
    switch ($rank) {
        case "S":
            // select count(*) from sheets where rank="S";
            $sheet_count = 50;
            break;
        case "A":
            // select count(*) from sheets where rank="A";
            $sheet_count = 150;
            break;
        case "B":
            // select count(*) from sheets where rank="B";
            $sheet_count = 300;
            break;
        // select count(*) from sheets where rank="C";
        case "C":
            $sheet_count = 500;
            break;
    }
    return $sheet_count;
}

function get_sheet_price($rank) {
    switch ($rank) {
        case "S":
            $sheet_price = 5000;
            break;
        case "A":
            $sheet_price = 3000;
            break;
        case "B":
            $sheet_price = 1000;
            break;
        case "C":
            $sheet_price = 0;
            break;
    }
    return $sheet_price;
}

//TODO here
function get_sheets_reserve_count($dbh, $event_id, $rank) {
    switch ($rank) {
        case "S":
            // select min(id), max(id) from sheets where rank="S";
            $min_sheet_id = 1;
            $max_sheet_id = 50;
            break;
        case "A":
            // select min(id), max(id) from sheets where rank="A";
            $min_sheet_id = 51;
            $max_sheet_id = 200;
            break;
        case "B":
            // select min(id), max(id) from sheets where rank="B";
            $min_sheet_id = 201;
            $max_sheet_id = 500;
            break;
            // select min(id), max(id) from sheets where rank="C";
        case "C":
            $min_sheet_id = 501;
            $max_sheet_id = 1000;
            break;
    }

    return $dbh->select_one('SELECT COUNT(*) FROM reservations WHERE `event_id` = ? AND `sheet_id` >= ? AND `sheet_id` <= ? AND canceled_at IS NULL', $event_id, $min_sheet_id, $max_sheet_id);
}

function get_event(PDOWrapper $dbh, int $event_id, ?int $login_user_id = null): array
{
    Analysis::time('get_event');
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    // zero fill
    $event['total'] = 0;
    $event['remains'] = 0;

    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['total'] = 0;
        $event['sheets'][$rank]['remains'] = 0;
    }

    $reservationList = $dbh->select_all('SELECT * FROM reservations WHERE event_id = ? AND canceled_at IS NULL ORDER BY sheet_id, reserved_at', $event['id']);
    $reservationMap = [];
    foreach ($reservationList as $reservation) {
        if (isset($reservationMap[$reservation['sheet_id']])) {
            continue;
        }
        $reservationMap[$reservation['sheet_id']] = $reservation;
    }

    $sheets = $dbh->select_all('SELECT * FROM sheets ORDER BY `rank`, num');
    foreach ($sheets as $sheet) {
        $event['sheets'][$sheet['rank']]['price'] = $event['sheets'][$sheet['rank']]['price'] ?? $event['price'] + $sheet['price'];

        ++$event['total'];
        ++$event['sheets'][$sheet['rank']]['total'];

        //$reservation = $dbh->select_row('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', $event['id'], $sheet['id']);
        $reservation = isset($reservationMap[$sheet['id']]) ? $reservationMap[$sheet['id']] : null;
        if ($reservation) {
            $sheet['mine'] = $login_user_id && $reservation['user_id'] == $login_user_id;
            $sheet['reserved'] = true;
            $sheet['reserved_at'] = (new \DateTime("{$reservation['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp();
        } else {
            ++$event['remains'];
            ++$event['sheets'][$sheet['rank']]['remains'];
        }

        $sheet['num'] = $sheet['num'];
        $rank = $sheet['rank'];
        unset($sheet['id']);
        unset($sheet['price']);
        unset($sheet['rank']);

        if (false === isset($event['sheets'][$rank]['detail'])) {
            $event['sheets'][$rank]['detail'] = [];
        }

        array_push($event['sheets'][$rank]['detail'], $sheet);
    }

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    Analysis::timeEnd('get_event');
    return $event;
}

function get_event_for_user(PDOWrapper $dbh, int $event_id, ?int $login_user_id = null): array
{
    Analysis::time('get_event_for_user');
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['price'] = $event['price'] + get_sheet_price($rank);
    }

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    Analysis::timeEnd('get_event_for_user');
    return $event;
}

function get_event_for_user_recent(PDOWrapper $dbh, int $event_id, ?int $login_user_id = null): array
{
    Analysis::time('get_event_for_user_recent');
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    // zero fill
    $event['total'] = 1000;
    $event['remains'] = 0;

    $remains_count = 0;
    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['total'] = get_total_sheets_count($rank);
        $event['sheets'][$rank]['remains'] = $event['sheets'][$rank]['total'] - get_sheets_reserve_count($dbh, $event_id, $rank);
        $event['sheets'][$rank]['price'] = $event['price'] + get_sheet_price($rank);
        $remains_count += $event['sheets'][$rank]['remains'];
    }
    $event['remains'] = $remains_count;

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    Analysis::timeEnd('get_event_for_user_recent');
    return $event;
}

function sanitize_event(array $event): array
{
    unset($event['price']);
    unset($event['public']);
    unset($event['closed']);

    return $event;
}

$app->post('/api/events/{id}/actions/reserve', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $rank = $request->getParsedBodyParam('sheet_rank');

    $user = get_login_user($this);
    // org
    //$event = get_event($this->dbh, $event_id, $user['id']);
    $event = get_event_for_delete($this->dbh, $event_id);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 400);
    }

    $sheet = null;
    $reservation_id = null;
    while (true) {
        $sheet = $this->dbh->select_row('SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', $event['id'], $rank);
        if (!$sheet) {
            return res_error($response, 'sold_out', 409);
        }

        $this->dbh->beginTransaction();
        try {
            $this->dbh->execute('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', $event['id'], $sheet['id'], $user['id'], (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'));
            $reservation_id = (int) $this->dbh->last_insert_id();

            $this->dbh->commit();
        } catch (\Exception $e) {
            $this->dbh->rollback();
            continue;
        }

        break;
    }

    return $response->withJson([
        'id' => $reservation_id,
        'sheet_rank' => $rank,
        'sheet_num' => $sheet['num'],
    ], 202, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->delete('/api/events/{id}/sheets/{ranks}/{num}/reservation', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $rank = $args['ranks'];
    $num = $args['num'];

    $user = get_login_user($this);
    // org
    //$event = get_event($this->dbh, $event_id, $user['id']);
    $event = get_event_for_delete($this->dbh, $event_id);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 404);
    }

    $sheet = $this->dbh->select_row('SELECT * FROM sheets WHERE `rank` = ? AND num = ?', $rank, $num);
    if (!$sheet) {
        return res_error($response, 'invalid_sheet', 404);
    }

    $this->dbh->beginTransaction();
    try {
        $reservation = $this->dbh->select_row('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', $event['id'], $sheet['id']);
        if (!$reservation) {
            $this->dbh->rollback();

            return res_error($response, 'not_reserved', 400);
        }

        if ($reservation['user_id'] != $user['id']) {
            $this->dbh->rollback();

            return res_error($response, 'not_permitted', 403);
        }

        $this->dbh->execute('UPDATE reservations SET canceled_at = ? WHERE id = ?', (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation['id']);
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();

        return res_error($response);
    }

    return $response->withStatus(204);
})->add($login_required);

//TODO get_event_for_delete
function get_event_for_delete(PDOWrapper $dbh, int $event_id): array
{
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    return $event;
}

function validate_rank(PDOWrapper $dbh, $rank)
{
    return $dbh->select_one('SELECT COUNT(*) FROM sheets WHERE `rank` = ?', $rank);
}

$admin_login_required = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if (!$administrator) {
        return res_error($response, 'admin_login_required', 401);
    }

    return $next($request, $response);
};

$fillin_administrator = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if ($administrator) {
        $this->view->offsetSet('administrator', $administrator);
    }

    return $next($request, $response);
};

$app->get('/admin/', function (Request $request, Response $response) {
    $events = get_events($this->dbh, function ($event) { return $event; });

    return $this->view->render($response, 'admin.twig', [
        'events' => $events,
    ]);
})->add($fillin_administrator);

$app->post('/admin/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $administrator = $this->dbh->select_row('SELECT * FROM administrators WHERE login_name = ?', $login_name);
    $pass_hash = $this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$administrator || $pass_hash != $administrator['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    /** @var Helper $session */
    $session = $this->session;
    $session->set('administrator_id', $administrator['id']);

    return $response->withJson($administrator, null, JSON_NUMERIC_CHECK);
});

$app->post('/admin/api/actions/logout', function (Request $request, Response $response): Response {
    /** @var Helper $session */
    $session = $this->session;
    $session->delete('administrator_id');

    return $response->withStatus(204);
})->add($admin_login_required);

/**
 * @param ContainerInterface $app*
 *
 * @return bool|array
 */
function get_login_administrator(ContainerInterface $app)
{
    /** @var Helper $session */
    $session = $app->session;
    $administrator_id = $session->get('administrator_id');
    if (null === $administrator_id) {
        return false;
    }

    $administrator = $app->dbh->select_row('SELECT id, nickname FROM administrators WHERE id = ?', $administrator_id);
    $administrator['id'] = (int) $administrator['id'];
    return $administrator;
}

$app->get('/admin/api/events', function (Request $request, Response $response): Response {
    $events = get_events($this->dbh, function ($event) { return $event; });

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events', function (Request $request, Response $response): Response {
    $title = $request->getParsedBodyParam('title');
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $price = $request->getParsedBodyParam('price');

    $event_id = null;

    $this->dbh->beginTransaction();
    try {
        $this->dbh->execute('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', $title, $public, $price);
        $event_id = $this->dbh->last_insert_id();
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $event = get_event($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events/{id}/actions/edit', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $closed = $request->getParsedBodyParam('closed') ? 1 : 0;

    if ($closed) {
        $public = 0;
    }

    //org
    //$event = get_event($this->dbh, $event_id);
    $event = get_event_for_delete($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    if ($event['closed']) {
        return res_error($response, 'cannot_edit_closed_event', 400);
    } elseif ($event['public'] && $closed) {
        return res_error($response, 'cannot_close_public_event', 400);
    }

    $this->dbh->beginTransaction();
    try {
        $this->dbh->execute('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', $public, $closed, $event['id']);
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/reports/events/{id}/sales', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    //org
    //$event = get_event($this->dbh, $event_id);
    $event = get_event_for_delete($this->dbh, $event_id);

    $reports = [];

    //org
    //$reservations = $this->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', $event['id']);
    //$reservations = $this->dbh->select_all('SELECT r.*, e.price AS event_price FROM reservations r INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', $event['id']);
    //$reservations = $this->dbh->select_all('SELECT * FROM reservations WHERE event_id = ? ORDER BY reserved_at ASC FOR UPDATE', $event['id']);
    $reservations = $this->dbh->select_all('SELECT * FROM reservations WHERE event_id = ? ORDER BY reserved_at ASC', $event['id']);
    foreach ($reservations as $reservation) {
        $sheet_id = $reservation['sheet_id'];
        $sheet_num = get_sheet_num($reservation['sheet_id']);
        $sheet_rank = get_sheet_rank($sheet_id);
        $report = [
            'reservation_id' => $reservation['id'],
            'event_id' => $reservation['event_id'],
            //'rank' => $reservation['sheet_rank'],
            'rank' => $sheet_rank,
            //'num' => $reservation['sheet_num'],
            'num' => $sheet_num,
            'user_id' => $reservation['user_id'],
            'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
            'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
            //org
            //'price' => $reservation['event_price'] + $reservation['sheet_price'],
            //'price' => $reservation['event_price'] + get_sheet_price($sheet_rank)
            'price' => $event['price'] + get_sheet_price($sheet_rank)
        ];

        array_push($reports, $report);
    }

    return render_report_csv($response, $reports);
});

function get_sheet_num($sheet_id) {
    if ($sheet_id >= 1 && $sheet_id <= 50) {
        return $sheet_id;
    } elseif ($sheet_id >= 51 && $sheet_id <= 200) {
        return $sheet_id - 50;
    } elseif ($sheet_id >= 201 && $sheet_id <= 500) {
        return $sheet_id - 200;
    } elseif ($sheet_id >= 501 && $sheet_id <= 1000) {
        return $sheet_id - 500;
    }
    return;
}

//TODO here
function get_sheet_rank($sheet_num) {
    if ($sheet_num >= 1 && $sheet_num <= 50) {
        return "S";
    } elseif ($sheet_num >= 51 && $sheet_num <= 200) {
        return "A";
    } elseif ($sheet_num >= 201 && $sheet_num <= 500) {
        return "B";
    } elseif ($sheet_num >= 501 && $sheet_num <= 1000) {
        return "C";
    }
    return;
}

$app->get('/admin/api/reports/sales', function (Request $request, Response $response): Response {
    $reports = [];

    $events = get_events_new($this->dbh);

    //$reservations = $this->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');
    //$reservations = $this->dbh->select_all('SELECT r.*, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');
    //$reservations = $this->dbh->select_all('SELECT * FROM reservations ORDER BY reserved_at ASC FOR UPDATE');
    $reservations = $this->dbh->select_all('SELECT * FROM reservations ORDER BY reserved_at ASC');
    foreach ($reservations as $reservation) {
        $sheet_id = $reservation['sheet_id'];
        $sheet_num = get_sheet_num($reservation['sheet_id']);
        $sheet_rank = get_sheet_rank($sheet_id);
        $report = [
            'reservation_id' => $reservation['id'],
            'event_id' => $reservation['event_id'],
            //'rank' => $reservation['sheet_rank'],
            'rank' => $sheet_rank,
            //'num' => $reservation['sheet_num'],
            'num' => $sheet_num,
            'user_id' => $reservation['user_id'],
            'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
            'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
            //'price' => $reservation['event_price'] + $reservation['sheet_price'],
            //'price' => $reservation['event_price'] + get_sheet_price($sheet_rank)
            'price' => $events[$reservation['event_id']]['price'] + get_sheet_price($sheet_rank)
        ];

        array_push($reports, $report);
    }

    return render_report_csv($response, $reports);
})->add($admin_login_required);

//TODO
function get_events_new($dbh) {
    $temp_events = $dbh->select_all('SELECT * FROM events ORDER BY id ASC');
    $events = [];
    foreach ($temp_events as $event) {
        $events[$event['id']] = $event;
    }

    return $events;
}

function render_report_csv(Response $response, array $reports): Response
{
    usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });

    $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
    $body = implode(',', $keys);
    $body .= "\n";
    foreach ($reports as $report) {
        $data = [];
        foreach ($keys as $key) {
            $data[] = $report[$key];
        }
        $body .= implode(',', $data);
        $body .= "\n";
    }

    return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
        ->write($body);
}

function res_error(Response $response, string $error = 'unknown', int $status = 500): Response
{
    return $response->withStatus($status)
        ->withHeader('Content-type', 'application/json')
        ->withJson(['error' => $error]);
}

class Analysis
{
    private static $timeMap = [];

    public static function time($label)
    {
        if (empty($label)) {
            return;
        }
        self::$timeMap[$label] = microtime(true);
    }

    public static function timeEnd($label)
    {
        if (empty($label)) {
            return;
        }
        error_log($_SERVER['REQUEST_URI'] . ' ' . $label . ' depth=' . count(self::$timeMap) . ' time=' . floor((microtime(true) - self::$timeMap[$label]) * 1000) . 'ms' . "\n", 3, '/tmp/analysis.log');
        unset(self::$timeMap[$label]);
    }
}
