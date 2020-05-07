<?php


namespace App\Domain\Diary;


class TranslationManager {

    private $translations = [
        [
            "from" => "category",
            "to" => [
                "kategoria",
                "kategorii"
            ],
        ],
        [
            "from" => "grade",
            "to" => [
                "ocena",
                "oceny"
            ],
        ],
        [
            "from" => "value",
            "to" => [
                "wartość",
                "wartości"
            ],
        ],
        [
            "from" => "weight",
            "to" => [
                "waga",
                "wagi"
            ],
        ],
        [
            "from" => "period",
            "to" => [
                "semestr",
                "semestru"
            ],
        ],
        [
            "from" => "average",
            "to" => [
                "stan liczenia do średniej",
                "stanu liczenia do średniej"
            ],
        ],
        [
            "from" => "individual",
            "to" => [
                "stan toku nauczania indywidualnego",
                "stanu toku nauczania indywidualnego"
            ],
        ],
        [
            "from" => "description",
            "to" => [
                "opis",
                "opisu"
            ],
        ],
        [
            "from" => "date",
            "to" => [
                "data",
                "daty"
            ],
        ],
        [
            "from" => "issuer",
            "to" => [
                "nauczyciel oceniający",
                "kategorii"
            ],
        ]
    ];


    /**
     * @param string $input
     * @param int $form
     * @return string
     */
    public function translate(string $input, int $form): string {

        foreach ($this->translations as $translation) {
            $from = $translation["from"];
            $to = $translation["to"][$form];

            $input = str_ireplace($from, $to, $input);
        }

        return $input;
    }
}