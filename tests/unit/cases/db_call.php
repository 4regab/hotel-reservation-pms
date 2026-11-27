<?php
declare(strict_types=1);

use Lib\Db;

return [
    'db: call builds EXEC with named parameters and one placeholder each' => function (): void {
        same('EXEC dbo.usp_room_get @room_id = ?', Db::callSql('usp_room_get', ['room_id']), 'one parameter');
        same('EXEC dbo.usp_room_list @status = ?, @floor = ?', Db::callSql('usp_room_list', ['status', 'floor']), 'two parameters');
        same('EXEC dbo.usp_ping', Db::callSql('usp_ping', []), 'no parameters');
    },
    'db: call refuses names that are not plain identifiers' => function (): void {
        throws(fn () => Db::callSql('usp_x; DROP TABLE room', []), InvalidArgumentException::class);
        throws(fn () => Db::callSql('usp_x', ['a = 1; --']), InvalidArgumentException::class);
    },
];
