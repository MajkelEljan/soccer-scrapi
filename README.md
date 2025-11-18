# Soccer ScrAPI - Plugin WordPress

**Wersja:** 1.5.0  
**Autor:** Majkel  
**Licencja:** GPL v2 or later

## 📋 Opis

Plugin WordPress do pobierania i wyświetlania danych piłkarskich z dwóch źródeł:

### ⚽ **Moduł Ekstraklasa**
- **Źródło:** SofaScore API (RapidAPI)
- **Funkcje:** Tabela ligowa, Terminarz, Kadra Wisły Płock
- **Zarządzanie:** Panel administracyjny z kontrolowanym pobieraniem rund

### 🥅 **Moduł Wisła II Płock - III Liga**
- **Źródło:** 90minut.pl (web scraping)
- **Funkcje:** Tabela III ligi, Terminarz, Kadra Wisły II
- **Zarządzanie:** Automatyczne pobieranie + upload CSV dla kadry

## 🚀 Instalacja

1. Wgraj folder `sofascore-ekstraklasa` do katalogu `/wp-content/plugins/`
2. Aktywuj plugin w panelu WordPress
3. Przejdź do **Soccer ScrAPI** w menu administracyjnym

## ⚙️ Konfiguracja

### Ekstraklasa (SofaScore API)
1. Uzyskaj klucz API z [RapidAPI](https://rapidapi.com/sofascore/api/sofascore)
2. Wprowadź klucz w ustawieniach pluginu
3. Użyj modułu **Terminarz Ekstraklasa** do pobierania rund

### Wisła II Płock (90minut.pl)
1. Moduł działa od razu (nie wymaga konfiguracji)
2. Dla kadry: użyj modułu **Kadra Wisły II** do uploadu pliku CSV

## 📋 Shortcodes

### ⚽ Ekstraklasa

#### Tabela ligowa
```
[tabela_ekstraklasa]
```

#### Zamrożona tabela ligowa
```
[tabela_ekstraklasa_zamrozona id="nazwa_tabeli" zapisz="tak"]
[tabela_ekstraklasa_zamrozona id="nazwa_tabeli"]
```
**Opis:** Pozwala zapisać aktualny stan tabeli i wyświetlać go bez aktualizacji - przydatne do artykułów historycznych.

#### Terminarz kolejki
```
[terminarz_ekstraklasa round="1"]
[terminarz_ekstraklasa round="15"]
```

#### Terminarz Wisły Płock
```
[terminarz_wisla]
[terminarz_wisla limit="20"]
```

#### Kadra Wisły Płock
```
[wisla_kadra]
[wisla_kadra pozycja="Napastnik"]
[wisla_kadra kolumny="4"]
```

### 🥅 Wisła II Płock

#### Tabela III Liga
```
[tabela_3_liga]
```

#### Terminarz III Liga
```
[terminarz_3_liga]
[terminarz_3_liga kolejka="1"]
```

#### Terminarz Wisły II Płock
```
[terminarz_wisla_ii]
[terminarz_wisla_ii limit="30"]
```

#### Kadra Wisły II Płock
```
[wisla_ii_kadra]
[wisla_ii_kadra pozycja="Bramkarz"]
[wisla_ii_kadra kolumny="2"]
[wisla_ii_kadra sortowanie="nazwisko"]
```

## 🎨 Funkcje specjalne

### Dla Ekstraklasy:
- **Wyróżnienie meczów Wisły** w terminarzach
- **Kontrolowane pobieranie rund** - oszczędność zapytań API
- **Cache 30 minut** dla wszystkich danych
- **Rzeczywista pozycja z API** - tabela pokazuje faktyczne miejsca

### Dla Wisły II:
- **Wisła II zawsze na 1. miejscu** w tabeli III ligi
- **Automatyczne filtrowanie** meczów Wisły II
- **Wyróżnienie meczów Wisły II** w terminarzach
- **Upload CSV** dla kadry przez panel administracyjny

## 🔧 Panel administracyjny

### Menu główne: **Soccer ScrAPI**
- Przegląd wszystkich modułów
- Test połączenia z API
- Lista wszystkich shortcodes

### **Terminarz Ekstraklasa**
- Zarządzanie rundami Ekstraklasy
- Pobieranie/aktualizacja konkretnych rund
- Lista zapisanych danych

### **Tabela Ekstraklasa**
- Aktualizacja tabeli ligowej
- Informacje o cache

### **Wisła II Płock**
- Aktualizacja danych III ligi
- Lista shortcodes dla Wisły II
- Informacje o module

### **Kadra Wisły II**
- Upload pliku CSV z kadrą
- Status pliku kadry
- Instrukcje formatu CSV

## 📁 Format CSV dla kadry

### Wisła Płock (Ekstraklasa)
Plik: `wisla-kadra.csv` w katalogu motywu

### Wisła II Płock (III Liga)
Plik: `wisla-ii-kadra.csv` w katalogu motywu

**Kolumny CSV:**
1. **Imię i nazwisko**
2. **Numer**
3. **Pozycja** (Bramkarz, Obrońca, Pomocnik, Napastnik)
4. **Wiek**
5. **Wzrost** (w cm, lub "N/A")
6. **Kraj**
7. **Noga** (lewa, prawa, obie, lub "N/A")
8. **Zdjęcie** (URL do zdjęcia, opcjonalne)

**Przykład:**
```csv
"Jan Kowalski",1,"Bramkarz",25,185,"Polska","prawa","https://example.com/photo.jpg"
"Adam Nowak",10,"Napastnik",22,178,"Polska","lewa",""
```

## 🎯 Responsywność

Plugin jest w pełni responsywny:
- **Desktop:** Pełne tabele i terminarz w poziomie
- **Tablet:** Dostosowany layout z zachowaniem funkcji
- **Mobile:** Pionowy układ, ukryte niepotrzebne kolumny

## 📊 Cache i wydajność

- **Ekstraklasa:** Cache 30 minut + kontrolowane pobieranie rund
- **Wisła II:** Cache 30 minut + automatyczne pobieranie
- **Optymalizacja:** Minimalne zapytania do zewnętrznych API

## 🔄 Aktualizacje

### Ekstraklasa:
- Ręczne przez panel "Terminarz Ekstraklasa"
- Automatyczne odświeżanie cache co 30 minut

### Wisła II:
- Ręczne przez panel "Wisła II Płock"
- Automatyczne odświeżanie cache co 30 minut

## 🛠️ Wymagania

- **WordPress:** 5.0+
- **PHP:** 7.4+
- **Ekstraklasa:** Klucz RapidAPI
- **Wisła II:** Połączenie internetowe (90minut.pl)

## 📞 Wsparcie

Plugin stworzony dla klubu Wisła Płock.

**Funkcje:**
- ✅ Tabela Ekstraklasy z rzeczywistymi pozycjami
- ✅ Terminarz Ekstraklasy z zarządzaniem rundami
- ✅ Terminarz Wisły Płock z wyróżnieniem
- ✅ Kadra Wisły Płock (API + edycja)
- ✅ Tabela III ligi z Wisłą II na 1. miejscu
- ✅ Terminarz III ligi
- ✅ Terminarz Wisły II Płock z wyróżnieniem
- ✅ Kadra Wisły II (CSV upload)
- ✅ Responsywny design
- ✅ Panel administracyjny
- ✅ Cache i optymalizacja

---

**Wersja 1.4.3** - Poprawiono kodowanie i dodano alternatywny parser

## Funkcje

### 🏆 Tabela ligowa
- Automatyczne pobieranie aktualnej tabeli Ekstraklasy
- Rzeczywiste pozycje zgodnie z API SofaScore
- Kolorowe oznaczenia kwalifikacji (Liga Mistrzów, Liga Konferencji, spadek)
- Responsywny design

### 📅 Terminarz meczów
- **Nowy system modułów** - zarządzanie rundami przez panel administracyjny
- Wybór konkretnych rund do pobrania (oszczędzanie zapytań API)
- Zapisywanie danych lokalnie w WordPress
- Kontrola nad aktualizacjami

### ⚙️ Panel administracyjny
- **Główne menu:** SofaScore Ekstraklasa
- **Moduł Terminarz:** Zarządzanie rundami i danymi
- **Moduł Tabela:** Aktualizacja tabeli ligowej
- Test połączenia z API

## Instalacja

1. Skopiuj folder `sofascore-ekstraklasa` do katalogu `/wp-content/plugins/`
2. Aktywuj plugin w panelu administracyjnym WordPress
3. Przejdź do **SofaScore Ekstraklasa** w menu administratora

## Konfiguracja

### Moduł Ekstraklasa (SofaScore API)

Plugin wymaga klucza API z RapidAPI dla SofaScore:

```php
// W pliku sofascore-ekstraklasa.php zmień:
private $api_key = 'TWÓJ_KLUCZ_API';
private $api_host = 'sportapi7.p.rapidapi.com';
```

### Moduł Wisła II Płock (90minut.pl)

1. Przejdź do **WordPress Admin → SofaScore Ekstraklasa → Wisła II Płock**
2. **Skonfiguruj URL źródła danych:**
   - Wpisz aktualny adres strony z 90minut.pl (np. `http://www.90minut.pl/liga/1/liga14154.html`)
   - Kliknij **"Zapisz konfigurację"**
3. Kliknij **"Aktualizuj dane Wisły II"** aby pobrać najnowsze dane

#### Znajdowanie URL dla nowego sezonu:
1. Idź na stronę [90minut.pl](http://www.90minut.pl)
2. Znajdź sekcję **III Liga** → **Grupa I**
3. Skopiuj URL ze strony z tabelą i terminarzem
4. Wklej go w konfiguracji pluginu

**Przykłady URL:**
- `http://www.90minut.pl/liga/1/liga14154.html` - Sezon 2025/26
- `http://www.90minut.pl/liga/1/liga13XXX.html` - Przyszłe sezony

## Shortcodes

### Tabela ligowa
```
[tabela_ekstraklasa]
```

**Parametry:**
- `season` - ID sezonu (domyślnie: 76477 dla sezonu 2024/25)
- `pokazuj_kwalifikacje` - czy pokazywać legendę kwalifikacji (tak/nie)

### Zamrożona tabela ligowa
```
[tabela_ekstraklasa_zamrozona id="nazwa_tabeli" zapisz="tak"]
[tabela_ekstraklasa_zamrozona id="nazwa_tabeli"]
```

**Parametry:**
- `id` - unikalny identyfikator tabeli (wymagany)
- `zapisz` - "tak" aby zapisać aktualną tabelę (domyślnie: "nie")
- `season` - ID sezonu (domyślnie: 76477)
- `pokazuj_kwalifikacje` - czy pokazywać legendę kwalifikacji (tak/nie)

**Opis:** Pozwala zapisać aktualny stan tabeli na dany moment i wyświetlać go bez aktualizacji. Przydatne do artykułów historycznych, gdzie chcemy pokazać tabelę z konkretnej daty.

**Przykłady użycia:**
- `[tabela_ekstraklasa_zamrozona id="poczatek_sezonu" zapisz="tak"]` - zapisuje aktualną tabelę
- `[tabela_ekstraklasa_zamrozona id="poczatek_sezonu"]` - wyświetla zapisaną tabelę

### Terminarz kolejki
```
[terminarz_ekstraklasa round="1"]
```

**Parametry:**
- `round` - numer rundy (1-34)
- `season` - ID sezonu (domyślnie: 76477)
- `limit` - maksymalna liczba meczów do wyświetlenia

### Terminarz Wisły Płock
```
[terminarz_wisla]
```

**Parametry:**
- `limit` - maksymalna liczba meczów (domyślnie: 20)
- `season` - ID sezonu (domyślnie: 76477)

**Funkcje:**
- Automatycznie zbiera mecze Wisły Płock ze wszystkich pobranych rund
- Sortuje chronologicznie według daty meczu
- Wyróżnia mecze domowe i wyjazdowe
- Pokazuje numer kolejki dla każdego meczu

## Zarządzanie rundami

### Jak pobrać dane dla rundy:

1. Przejdź do **SofaScore Ekstraklasa → Terminarz**
2. Kliknij **"Załaduj listę rund"**
3. Wybierz rundę i kliknij **"Pobierz"** lub **"Aktualizuj"**
4. Dane zostaną zapisane lokalnie w WordPress

### Korzyści tego podejścia:
- **Oszczędność API:** Pobierasz tylko potrzebne rundy
- **Kontrola kosztów:** Unikasz przekroczenia limitów RapidAPI  
- **Szybkość:** Dane lokalne ładują się błyskawicznie
- **Niezawodność:** Brak zależności od dostępności API przy wyświetlaniu

## Struktura danych

### Zapisane rundy
Dane przechowywane w `wp_options` jako `sofascore_saved_rounds`:

```php
array(
    '1' => array(
        'data' => [...], // Pełne dane API
        'updated' => '2024-01-15 14:30:00',
        'matches_count' => 9
    ),
    '2' => array(
        'data' => [...],
        'updated' => '2024-01-22 16:45:00', 
        'matches_count' => 9
    )
)
```

## API Endpoints

- **Tabela:** `/unique-tournament/202/season/{season_id}/standings/total`
- **Runda:** `/unique-tournament/202/season/{season_id}/events/round/{round}`
- **Sezon:** `/unique-tournament/202/season/{season_id}/events`

## Cache

- **Tabela:** 1 godzina
- **Terminarz:** Bez automatycznego cache (zarządzane ręcznie przez moduły)

## Troubleshooting

### Błąd 403 - Forbidden
- Sprawdź poprawność klucza API
- Zweryfikuj czy endpoint jest dostępny w Twoim planie RapidAPI
- Sprawdź limity zapytań

### Brak danych dla rundy
- Przejdź do modułu Terminarz i pobierz dane dla konkretnej rundy
- Sprawdź czy runda istnieje (Ekstraklasa ma 34 rundy)

### Problemy z wyświetlaniem
- Sprawdź czy shortcode ma poprawny numer rundy
- Zweryfikuj czy dane zostały pobrane w panelu administracyjnym

## Rozwój

Plugin można rozszerzyć o:
- Inne ligi (zmiana tournament ID)
- Statystyki zawodników
- Wyniki na żywo
- Automatyczne aktualizacje w określonych godzinach

## Wsparcie

W przypadku problemów:
1. Sprawdź logi WordPress (`wp-content/debug.log`)
2. Przetestuj połączenie API w panelu administracyjnym
3. Zweryfikuj konfigurację RapidAPI

## 📋 Changelog

### Wersja 1.5.0 (2025-01-26)
- **Wyniki zakończonych meczów w terminarzach:**
  - Dodano funkcję get_event_details() do pobierania szczegółów meczu z API
  - Format: "Drużyna A - Drużyna B" po lewej, "**2:1** (1:0)" po prawej
  - Wynik końcowy pogrubiony, wynik do przerwy w nawiasach bez pogrubienia
  - Dla zakończonych meczów nie wyświetla się data/godzina ani status
  - Cache wyników na 24 godziny (wyniki się nie zmieniają)
  - Style CSS: wyniki po prawej stronie bez tła, separator "-" zamiast "vs"
  - Funkcjonalność działa w obu terminarzach: Ekstraklasa i Wisła Płock
  - Endpoint API: `/api/v1/event/{event_id}` dla szczegółów meczu

### Wersja 1.4.9 (2025-01-26)
- **Białe tło terminarzów:**
  - Zmieniono tło wszystkich terminarzów z niebieskawo-szarego gradientu na białe
  - Mecze Wisły II Płock zachowują delikatne szare tło (#f0f4f8) z pomarańczowym paskiem z lewej strony
  - Ujednolicono wygląd z terminarzem Ekstraklasy
  - Poprawiono czytelność i spójność wizualną

### Wersja 1.4.8 (2025-01-26)
- **Poprawka numerowania pozycji w tabeli III ligi:**
  - Wisła II Płock zawsze na 1. miejscu
  - Drużyny z pozycji 1-16: automatycznie +1 pozycja (bo Wisła II zajęła 1. miejsce)
  - Ostatnie dwie pozycje (17-18) zostają bez zmiany
  - Logiczna numeracja: 1, 2, 3, 4... 16, 17, 18

### Wersja 1.4.7 (2025-01-26)
- **Biała czcionka w nagłówkach kolejek:**
  - Dodano białą czcionkę do wszystkich nagłówków kolejek w terminarzach
  - Ujednolicono style nagłówków we wszystkich modułach
  - Poprawiono czytelność nagłówków na ciemnym tle

### Wersja 1.4.6 (2025-01-26)
- **Poprawki formatowania terminarzów:**
  - Usunięto niepotrzebne pomarańczowe wyróżnienie z terminarzą Wisły II
  - Dodano numery kolejek do terminarzą Wisły II (etykiety "Kolejka X")
  - Dodano wyraźny podział na kolejki w terminarzę III ligi z nagłówkami "Kolejka X - data"
  - Zachowano pomarańczowe wyróżnienie meczów Wisły w terminarzę wszystkich meczów III ligi

### Wersja 1.4.5 (2025-01-26)
- **Dodano CSS z powrotem do terminarzów:**
  - Przywrócono pełne style CSS dla terminarzów III ligi i Wisły II
  - Dodano responsywność dla urządzeń mobilnych
  - Poprawiono formatowanie nagłówków kolejek

### Wersja 1.4.4 (2025-01-26)
- **Poprawki kodowania dla terminarzą:**
  - Naprawiono obsługę kodowania w funkcji parse_3_liga_fixtures()
  - Dodano sprawdzanie różnych kodowań (ISO-8859-2, Windows-1250, CP1250)

### Wersja 1.4.3 (2025-01-26)
- **Poprawki parsowania i kodowania:**
  - Poprawiono obsługę kodowania (usunięto problematyczne mb_convert_encoding() z 'auto')
  - Dodano alternatywny parser dla przypadków gdy pierwszy regex nie znajdzie wierszy
  - Rozszerzone debugowanie dla lepszej diagnostyki problemów
  - Zmieniono logikę numerowania pozycji (automatyczne)

### Wersja 1.4.2 (2025-01-26)
- **Poprawki parsowania HTML z 90minut.pl:**
  - Poprawiono regex do wyciągania wierszy z kolorowym tłem
  - Zmieniono logikę numerowania pozycji w tabeli
  - Zmniejszono wymagania z 7 do 5 liczb na wiersz
  - Dostosowano parser do nowej struktury strony

### Wersja 1.4.1 (2025-01-26)
- **Konfigurowalny URL dla 90minut.pl:**
  - Dodano sekcję konfiguracji URL w panelu administracyjnym
  - Możliwość ustawienia własnego URL z 90minut.pl
  - Automatyczne czyszczenie cache po zmianie URL
  - Walidacja i bezpieczeństwo (WordPress nonce)

### Wersja 1.4.0 (2025-01-26)
- **Zmiana nazwy pluginu na "Soccer ScrAPI"**
- **Rozszerzenie o moduł Wisły II Płock (III Liga):**
  - Dodano scraping danych z 90minut.pl
  - Tabela III ligi z automatycznym wyróżnieniem Wisły II
  - Terminarz III ligi z podziałem na kolejki
  - Terminarz meczów Wisły II Płock
  - Kadra Wisły II z uploadem CSV
  - Panel administracyjny dla modułu Wisły II
- **Poprawki czasów w terminarzach:** +2 godziny do wszystkich timestampów 