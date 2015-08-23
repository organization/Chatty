<?php

namespace ifteam\Chatty;

use chalk\utils\Messages;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\PluginCommand;
use pocketmine\utils\TextFormat;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\network\protocol\AddPlayerPacket;
use pocketmine\network\protocol\RemovePlayerPacket;
use pocketmine\entity\Entity;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\Player;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\network\protocol\TextPacket;
use pocketmine\event\TranslationContainer;
use ifteam\Chatty\dummy\DummyInterface;
use ifteam\Chatty\dummy\DummyPlayer;

class Chatty extends PluginBase implements Listener {
	/** @var array */
	public $db = [ ], $packets = [ ], $lastNametags = [ ], $messageStack = [ ];

	/** @var NetworkInterlocker */
	public $interlocker;

	/** @var DummyInterface */
	public $dummyInterface;
	
	/**
	 *
	 * @var Messages
	 */
	public $messages = null;
	const MESSAGE_VERSION = 1;

	const MESSAGE_LENGTH = 50;
	const MESSAGE_MAX_LINES = 5;

	public function onEnable() {
		@mkdir ( $this->getDataFolder () );
		$this->saveDefaultConfig ();
		
		$this->initMessages ();
		
		$this->db = (new Config ( $this->getDataFolder () . "database.yml", Config::YAML, [ ] ))->getAll ();
		
		$this->packets ["AddPlayerPacket"] = new AddPlayerPacket ();
		$this->packets ["AddPlayerPacket"]->clientID = 0;
		$this->packets ["AddPlayerPacket"]->yaw = 0;
		$this->packets ["AddPlayerPacket"]->pitch = 0;
		$this->packets ["AddPlayerPacket"]->metadata = [ 
				Entity::DATA_FLAGS => [ 
						Entity::DATA_TYPE_BYTE,
						1 << Entity::DATA_FLAG_INVISIBLE 
				],
				Entity::DATA_AIR => [ 
						Entity::DATA_TYPE_SHORT,
						20 
				],
				Entity::DATA_SHOW_NAMETAG => [ 
						Entity::DATA_TYPE_BYTE,
						1 
				] 
		];
		
		$this->dummyInterface = new DummyInterface ( $this->getServer () );
		$this->interlocker = new NetworkInterlocker($this);

		$this->registerCommand ( $this->getMessage ( "chatty-command-name" ), "Chatty.commands", $this->getMessage ( "chatty-command-description" ), "/" . $this->getMessage ( "chatty-command-name" ) );
		
		$this->packets ["RemovePlayerPacket"] = new RemovePlayerPacket ();
		$this->packets ["RemovePlayerPacket"]->clientID = 0;
		
		$this->getServer ()->getPluginManager ()->registerEvents ( $this, $this );
		$this->getServer ()->getScheduler ()->scheduleRepeatingTask ( new ChattyTask ( $this ), 1 );
	}
	public function onDisable() {
		$save = new Config ( $this->getDataFolder () . "database.yml", Config::YAML );
		$save->setAll ( $this->db );
		$save->save ();
		if ($this->interlocker->dummyPlayer instanceof DummyPlayer) {
			$this->interlocker->dummyPlayer->loggedIn = false;
			$this->interlocker->dummyPlayer->close ();
		}
	}
	
	/**
	 *
	 * @param string $name
	 * @param string $permission			
	 * @param string $description			
	 * @param string $usage			
	 */
	public function registerCommand($name, $permission, $description = "", $usage = "") {
		$commandMap = $this->getServer ()->getCommandMap ();
		$command = new PluginCommand ( $name, $this );
		$command->setDescription ( $description );
		$command->setPermission ( $permission );
		$command->setUsage ( $usage );
		$commandMap->register ( $name, $command );
	}
	/**
	 *
	 * @return Messages
	 */
	public function getMessages() {
		return $this->messages;
	}
	
	/**
	 *
	 * @param string $key			
	 * @param array $format			
	 * @return null|string
	 */
	public function getMessage($key, $format = []) {
		return $this->getMessages ()->getMessage ( $key, $format, $this->getServer ()->getLanguage ()->getLang () );
	}
	public function initMessages() {
		$this->saveResource ( "messages.yml", false );
		$this->updateMessages ( "messages.yml" );
		
		$this->messages = new Messages( (new Config ( $this->getDataFolder () . "messages.yml", Config::YAML ))->getAll () );
	}
	public function updateMessages($filename) {
		$messages = (new Config ( $this->getDataFolder () . $filename, Config::YAML ))->getAll ();
		if (! isset ( $messages ["version"] ) or $messages ["version"] < self::MESSAGE_VERSION) {
			$this->saveResource ( $filename, true );
		}
	}
	public function onLogin(PlayerLoginEvent $event) {
		$name = $event->getPlayer ()->getName ();
		
		$this->messageStack [$name] = [ ];
		if (! isset ( $this->db [$name] )) {
			$this->db [$name] = [ ];
			$this->db [$name] ["chat"] = true;
			$this->db [$name] ["nametag"] = false;
			$this->db [$name] ["local-chat"] = false;
		}
	}
	public function onQuit(PlayerQuitEvent $event) {
		unset ( $this->messageStack [$event->getPlayer ()->getName ()] );
		unset ( $this->lastNametags [$event->getPlayer ()->getName ()] );
	}
	public function putStack($key, $message) {
		for($start = 0; $start < mb_strlen ( $message, "UTF-8" ); $start += self::MESSAGE_LENGTH) {
			$this->messageStack [$key] [] = mb_substr ( $message, $start, self::MESSAGE_LENGTH, "UTF-8" );
		}

		$this->messageStack [$key] = array_slice ( $this->messageStack [$key], - self::MESSAGE_MAX_LINES );
		
		foreach ( $this->messageStack [$key] as $index => $message ) {
			$this->messageStack [$key] [$index] = TextFormat::WHITE . $message;
		}
	}
	public function prePlayerCommand(PlayerCommandPreprocessEvent $event) {
		$message = $event->getMessage();

		//채팅이 아닌 명령어
		if(strpos($message, "/") === 0){
			return;
		}

		if($event->getPlayer() instanceof DummyPlayer){
			return;
		}
		
		$event->setCancelled(true);
		$sender = $event->getPlayer();

		//만약 확성기를 사용할 수 있고, 메세지에 @all을 넣어서 확성기를 사용했으며
		if(($this->getConfig()->get("loudspeaker-enabled", false) and (strpos($message, "@all") === 0)) and

		//확성기가 유료 서비스인데
		(($cost = intval($this->getConfig()->get("loudspeaker-cost", 10))) > 0) and

		//아예 이코노미API가 없어서 지불 불가이거나
		((($api = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")) === null) or

		//결재가 실패해서 (아마 돈이 모자라서) 지불 불가라면
		(\onebone\economyapi\EconomyAPI::getInstance()->reduceMoney($sender, $cost) !== \onebone\economyapi\EconomyAPI::RET_SUCCESS))){

		//메세지에서 @all을 도로 제거해서 확성기 사용을 막음
		$message = substr($message, 4); }

		
		$this->getServer ()->getPluginManager ()->callEvent ( $myEvent = new PlayerChatEvent($sender, $message) );
		if ($myEvent->isCancelled ()) {
			return;
		}
		
		$message = $this->getServer ()->getLanguage ()->translateString ( $myEvent->getFormat (), [ 
				$myEvent->getPlayer ()->getDisplayName (),
				$message
		] );
		
		// $event
		// $message
		$this->getServer ()->getScheduler ()->scheduleDelayedTask ( new CommandPreprocessEventTask ( $this, $event, $message ), 1 );

		//$this->broadcastMessage($message, $sender);
		//FIXME: 여기에 위 주석의 코드가 있어야 하는 거 아닌가요?
	}

	/**
	 *
	 * @param string $message			
	 * @param Player $sender			
	 */
	public function broadcastMessage($message, $sender = null){
		//콘솔에 메세지 출력
		$this->getLogger()->info ($message);

		//접속한 플레이어들에게 전달
		foreach($this->getServer()->getOnlinePlayers() as $player){

			//강제 근거리 채팅 모드이거나 해당 플레이어가 근거리 채팅 모드를 켠 경우
			if($this->getConfig()->get("local-chat-only", false) or (isset($this->db[$player->getName()]["local-chat"]) and $this->db[$player->getName()]["local-chat"] == true)){

				//@all을 넣어서 확성기를 쓴 경우나 해당 플레이어를 호명한 경우는 멀거나 다른 서버에서 말했어도 반드시 전달됨
				if(strpos($message, "@" . $player->getName()) === false and strpos($message, "@all") !== 0){

					//멀거나 다른 서버에서 말한 경우
					if($sender === null or ($sender->distance($player) > intval($this->getConfig()->get("local-chat-distance", 50)))){
						continue;
					}
				}
			}

			//받아라 얍
			$player->sendMessage($message);
		}
	}

	public function onDataPacket(DataPacketSendEvent $event) {
		if (! $event->getPacket () instanceof TextPacket or $event->getPacket ()->pid () != 0x85 or $event->isCancelled ()) {
			return;
		}
		
		if (isset ( $this->db [$event->getPlayer ()->getName ()] ["chat"] ) and $this->db [$event->getPlayer ()->getName ()] ["chat"] == false) {
			$event->setCancelled ();
			return;
		}
		
		if (isset ( $this->db [$event->getPlayer ()->getName ()] ["nametag"] ) and $this->db [$event->getPlayer ()->getName ()] ["nametag"] == true) {
			$message = $this->getServer ()->getLanguage ()->translate ( new TranslationContainer ( $event->getPacket ()->message, $event->getPacket ()->parameters ) );
			$this->putStack ( $event->getPlayer ()->getName (), $message );
		}
	}
	public function tick() {
		foreach ( $this->getServer ()->getOnlinePlayers () as $player ) {
			$key = $player->getName ();
			
			if (isset ( $this->lastNametags [$key] )) {
				$this->packets ["RemovePlayerPacket"]->eid = $this->lastNametags [$key] ["eid"];
				$this->packets ["RemovePlayerPacket"]->clientID = $this->lastNametags [$key] ["eid"];
				
				$player->directDataPacket ( $this->packets ["RemovePlayerPacket"] ); // 네임택 제거패킷 전송
			}
			
			if (! isset ( $this->db [$key] ["nametag"] ) or $this->db [$key] ["nametag"] == false or ! isset ( $this->messageStack [$key] )) {
				continue;
			}
			
			$this->lastNametags [$key] = [ 
					"eid" => Entity::$entityCount ++ 
			];
			
			$this->packets ["AddPlayerPacket"]->eid = $this->lastNametags [$key] ["eid"];
			$this->packets ["AddPlayerPacket"]->clientID = $this->lastNametags [$key] ["eid"];
			$this->packets ["AddPlayerPacket"]->username = implode ( "\n", $this->messageStack [$key] );
			$this->packets ["AddPlayerPacket"]->x = round ( $player->x ) + (- \sin ( ($player->yaw / 180 * M_PI) - 0.4 )) * 7;
			$this->packets ["AddPlayerPacket"]->y = round ( $player->y ) + (- \sin ( $player->pitch / 180 * M_PI )) * 7;
			$this->packets ["AddPlayerPacket"]->z = round ( $player->z ) + (cos ( ($player->yaw / 180 * M_PI) - 0.4 )) * 7;
			
			$player->dataPacket ( $this->packets ["AddPlayerPacket"] );
		}
	}
	public function sendMessage(CommandSender $player, $text, $prefix = null) {
		if ($prefix === null) {
			$prefix = $this->getMessage ( "default-prefix" );
		}
		
		$player->sendMessage ( TextFormat::DARK_AQUA . $prefix . " " . $this->getMessage ( $text ) );
	}
	public function sendAlert(CommandSender $player, $text, $prefix = null) {
		if ($prefix === null) {
			$prefix = $this->getMessage ( "default-prefix" );
		}
		
		$player->sendMessage ( TextFormat::RED . $prefix . " " . $this->getMessage ( $text ) );
	}
	public function onCommand(CommandSender $player, Command $command, $label, Array $args) {
		if (strToLower ( $command->getName () ) !== $this->getMessage ( "chatty-command-name" )) {
			return true;
		}
		
		if (! isset ( $args [0] )) {
			$this->sendMessage ( $player, "chatty-command-usage" );
			return true;
		}
		
		if (! $player instanceof Player) {
			$this->sendAlert ( $player, "only-in-game" );
			return true;
		}
		
		switch ($args [0]) {
			default :
				$this->sendMessage ( $player, "chatty-command-usage" );
				break;
			
			case $this->getMessage ( "chatty-on-command-name" ) :
				$this->db [$player->getName ()] ["chat"] = true;
				$this->sendMessage ( $player, "chat-enabled" );
				break;
			
			case $this->getMessage ( "chatty-off-command-name" ) :
				$this->db [$player->getName ()] ["chat"] = false;
				$this->sendMessage ( $player, "chat-disabled" );
				break;
			
			case $this->getMessage ( "chatty-local-chat-command-name" ) :
				if (! isset ( $this->db [$player->getName ()] ["local-chat"] ) or $this->db [$player->getName ()] ["local-chat"] == false) {
					$this->db [$player->getName ()] ["local-chat"] = true;
					$this->sendMessage ( $player, "local-chat-enabled" );
				} else {
					$this->db [$player->getName ()] ["local-chat"] = false;
					$this->sendMessage ( $player, "local-chat-disabled" );
				}
				break;
			
			case $this->getMessage ( "chatty-nametag-command-name" ) :
				if (! isset ( $this->db [$player->getName ()] ["nametag"] ) or $this->db [$player->getName ()] ["nametag"] == false) {
					$this->db [$player->getName ()] ["nametag"] = true;
					$this->sendMessage ( $player, "nametag-enabled" );
				} else {
					$this->db [$player->getName ()] ["nametag"] = false;
					$this->sendMessage ( $player, "nametag-disabled" );
				}
				break;
		}
		return true;
	}
}

?>
