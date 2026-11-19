<?php
declare(strict_types=1);

function record_restaurant_menu_visit(PDO $db,int $restaurantId):void
{
    try{
        $statement=$db->prepare('INSERT INTO restaurant_menu_visits(restaurant_id,visit_count,last_visited_at) VALUES(?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE visit_count=visit_count+1,last_visited_at=UTC_TIMESTAMP()');
        $statement->execute([$restaurantId]);
    }catch(PDOException $error){
        error_log('Unable to record restaurant menu visit: '.$error->getMessage());
    }
}
