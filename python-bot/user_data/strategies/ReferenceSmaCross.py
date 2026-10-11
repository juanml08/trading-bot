"""Estrategia de REFERENCIA para validar la infraestructura. No es una estrategia candidata:
cruce SMA 20/50 en 1h, sin optimizacion de parametros."""
from pandas import DataFrame

import talib.abstract as ta
from freqtrade.strategy import IStrategy


class ReferenceSmaCross(IStrategy):
    INTERFACE_VERSION = 3
    timeframe = "1h"
    can_short = False
    minimal_roi = {"0": 100}  # desactivado: la salida es por cruce o stoploss
    stoploss = -0.05
    trailing_stop = False
    process_only_new_candles = True
    startup_candle_count = 50

    def populate_indicators(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe["sma_fast"] = ta.SMA(dataframe, timeperiod=20)
        dataframe["sma_slow"] = ta.SMA(dataframe, timeperiod=50)
        return dataframe

    def populate_entry_trend(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        cross_up = (dataframe["sma_fast"] > dataframe["sma_slow"]) & (
            dataframe["sma_fast"].shift(1) <= dataframe["sma_slow"].shift(1)
        )
        dataframe.loc[cross_up & (dataframe["volume"] > 0), "enter_long"] = 1
        return dataframe

    def populate_exit_trend(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        cross_down = (dataframe["sma_fast"] < dataframe["sma_slow"]) & (
            dataframe["sma_fast"].shift(1) >= dataframe["sma_slow"].shift(1)
        )
        dataframe.loc[cross_down, "exit_long"] = 1
        return dataframe
