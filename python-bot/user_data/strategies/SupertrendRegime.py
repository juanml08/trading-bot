"""Hipotesis correctiva (research/strategy-audit/AUDIT.md): Supertrend SIN cambios en senales/salidas, mas UN filtro de entrada:
no abrir largos si el par cayo mas de 10% en los ultimos 30 dias (720 velas 1h, solo datos pasados).
Umbral fijado a priori (numero redondo), no ajustado. La copia original `Supertrend.py` queda intacta."""
from pandas import DataFrame
from Supertrend import Supertrend


class SupertrendRegime(Supertrend):
    startup_candle_count = 920  # 720 del filtro + 199 de Supertrend
    regime_lookback = 720
    regime_min_return = -0.10

    def populate_indicators(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe = super().populate_indicators(dataframe, metadata)
        dataframe["ret30d"] = dataframe["close"].pct_change(self.regime_lookback)
        return dataframe

    def populate_entry_trend(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe = super().populate_entry_trend(dataframe, metadata)
        dataframe.loc[dataframe["ret30d"] < self.regime_min_return, "enter_long"] = 0
        return dataframe
