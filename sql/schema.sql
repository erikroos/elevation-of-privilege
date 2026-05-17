-- Elevation of Privilege / LINDDUN GO — database schema
-- MySQL 5.7+ / MariaDB 10.3+. InnoDB + utf8mb4.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Master deck: alle kaarten van STRIDE (EoP) en LINDDUN (GO)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cards (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deck_type       ENUM('stride','linddun') NOT NULL,
  suit_key        VARCHAR(8)   NOT NULL,
  suit_name       VARCHAR(64)  NOT NULL,
  rank_code       VARCHAR(2)   NOT NULL,
  rank_value      TINYINT UNSIGNED NOT NULL,
  title           VARCHAR(255) NOT NULL,
  description     TEXT         NOT NULL,
  source          VARCHAR(255) NOT NULL,
  license         VARCHAR(64)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_deck_suit_rank (deck_type, suit_key, rank_code),
  KEY idx_deck (deck_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Spellen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              CHAR(6)      NOT NULL,
  deck_type         ENUM('stride','linddun') NOT NULL,
  diagram_path      VARCHAR(255) DEFAULT NULL,
  diagram_mime      VARCHAR(64)  DEFAULT NULL,
  status            ENUM('lobby','playing','finished') NOT NULL DEFAULT 'lobby',
  trump_suit        VARCHAR(8)   DEFAULT NULL,
  current_trick_id  INT UNSIGNED DEFAULT NULL,
  state_version     INT UNSIGNED NOT NULL DEFAULT 0,
  facilitator_token CHAR(64)     NOT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Spelers binnen een spel
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS players (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id         INT UNSIGNED NOT NULL,
  nickname        VARCHAR(40)  NOT NULL,
  seat_order      TINYINT UNSIGNED NOT NULL,
  score           INT NOT NULL DEFAULT 0,
  is_facilitator  TINYINT(1)   NOT NULL DEFAULT 0,
  session_token   CHAR(64)     NOT NULL,
  joined_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token (session_token),
  UNIQUE KEY uniq_game_seat (game_id, seat_order),
  KEY idx_game (game_id),
  CONSTRAINT fk_players_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Kaarten in de hand van een speler (gespeelde = played_at NOT NULL)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  card_id     INT UNSIGNED NOT NULL,
  played_at   DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_player (player_id),
  KEY idx_game (game_id),
  CONSTRAINT fk_hands_game   FOREIGN KEY (game_id)   REFERENCES games(id)   ON DELETE CASCADE,
  CONSTRAINT fk_hands_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_hands_card   FOREIGN KEY (card_id)   REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Tricks (één 'ronde' van één kaart per speler)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tricks (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id           INT UNSIGNED NOT NULL,
  trick_number      INT UNSIGNED NOT NULL,
  lead_player_id    INT UNSIGNED NOT NULL,
  lead_suit         VARCHAR(8)   DEFAULT NULL,
  winner_player_id  INT UNSIGNED DEFAULT NULL,
  current_player_id INT UNSIGNED DEFAULT NULL,
  completed_at      DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_game_tricknum (game_id, trick_number),
  CONSTRAINT fk_tricks_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Plays binnen een trick
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plays (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  trick_id            INT UNSIGNED NOT NULL,
  player_id           INT UNSIGNED NOT NULL,
  card_id             INT UNSIGNED NOT NULL,
  play_order          TINYINT UNSIGNED NOT NULL,
  threat_description  TEXT         DEFAULT NULL,
  threat_skipped      TINYINT(1)   NOT NULL DEFAULT 0,
  played_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_trick_order (trick_id, play_order),
  KEY idx_trick (trick_id),
  CONSTRAINT fk_plays_trick  FOREIGN KEY (trick_id)  REFERENCES tricks(id)  ON DELETE CASCADE,
  CONSTRAINT fk_plays_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_plays_card   FOREIGN KEY (card_id)   REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Events — kleine activiteits-log voor flash-toasts in de UI.
-- Bevat pre-rendered Nederlandse zinnen ("Anne speelde Spoofing 7") zodat
-- de poller geen joins/lookups hoeft te doen om de toast te tonen.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(32)  NOT NULL,
  message     VARCHAR(255) NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_id (game_id, id),
  CONSTRAINT fk_events_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Threats — geïdentificeerde threats per spel (voor export)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS threats (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  play_id     INT UNSIGNED NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  card_id     INT UNSIGNED NOT NULL,
  description TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game (game_id),
  CONSTRAINT fk_threats_game   FOREIGN KEY (game_id)   REFERENCES games(id)   ON DELETE CASCADE,
  CONSTRAINT fk_threats_play   FOREIGN KEY (play_id)   REFERENCES plays(id)   ON DELETE CASCADE,
  CONSTRAINT fk_threats_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_threats_card   FOREIGN KEY (card_id)   REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
