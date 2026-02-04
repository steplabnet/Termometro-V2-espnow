#!/usr/bin/env python
# -*- coding: utf-8 -*-

import os
import datetime
import urllib.request
from time import sleep, time

import numpy as np
import board
import busio
import smbus
from ctypes import c_short, c_byte, c_ubyte
from RPi import GPIO

# -------- GPIO / FAN --------
GPIO.setmode(GPIO.BCM)
GPIO.setup(15, GPIO.OUT)
GPIO.output(15, GPIO.LOW)
fanMode = 0  # ventola spenta

# -------- I2C (Linux bus) --------
# On Raspberry Pi usually bus 1
bus = smbus.SMBus(1)

# -------- ADS1115 --------
i2c = busio.I2C(board.SCL, board.SDA)
import adafruit_ads1x15.ads1115 as ADS
from adafruit_ads1x15.analog_in import AnalogIn

ads = ADS.ADS1115(i2c)
chan = AnalogIn(ads, ADS.P0, ADS.P1)  # differential

ADCgains = [1, 2, 4, 8, 16]
ads.gain = 1
adcGainIdx = 0

# -------- Tinkerforge --------
from tinkerforge.ip_connection import IPConnection
from tinkerforge.bricklet_outdoor_weather import BrickletOutdoorWeather

HOST = "localhost"
PORT = 4223
UID = "EEL"  # weather bricklet uid
mainStation = 193
sensoreTemperatura = 37
sensoreTemperatura2 = 96

# -------- Globals --------
DEVICE = 0x76  # BME280 I2C address
log_int = 1000  # seconds
tb = 0
urltime = 0

tensioni = [0.0] * 20
powerPrev = 0
fanHistory = np.array([48] * 10, dtype=float)  # moving avg of CPU temp


def left_shift(arr, value):
    for i in range(len(arr) - 1):
        arr[i] = arr[i + 1]
    arr[len(arr) - 1] = value
    return arr


def volt_average(arr):
    # average first 9 elements (like original)
    s = 0.0
    for i in range(9):
        s += arr[i]
    return s / 9.0


def temperature_of_raspberry_pi():
    global fanMode, fanHistory

    cpu_temp = os.popen("vcgencmd measure_temp").readline()
    cpu_temp = cpu_temp.replace("'C", "").replace("temp=", "")
    thermoTemp = float(cpu_temp[0:4])

    # update rolling array (size 10)
    fanHistory = np.roll(fanHistory, -1)
    fanHistory[-1] = thermoTemp
    ctMedia = fanHistory.mean()

    if ctMedia > 53:
        GPIO.output(15, GPIO.HIGH)
        fanMode = 1
    elif ctMedia < 49:
        GPIO.output(15, GPIO.LOW)
        fanMode = 0

    return ctMedia


def log_write(message):
    try:
        with open("/home/pi/logs/logs.txt", "a") as f:
            now = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
            f.write(f"{message}:{now}\n")
    except Exception as e:
        print("Log error:", e)


# ---- BME280 helper functions (same as yours) ----
def getShort(data, index):
    return c_short((data[index + 1] << 8) + data[index]).value


def getUShort(data, index):
    return (data[index + 1] << 8) + data[index]


def getChar(data, index):
    result = data[index]
    if result > 127:
        result -= 256
    return result


def getUChar(data, index):
    return data[index] & 0xFF


def readBME280All(addr=DEVICE):
    REG_DATA = 0xF7
    REG_CONTROL = 0xF4
    REG_CONFIG = 0xF5

    REG_CONTROL_HUM = 0xF2

    OVERSAMPLE_TEMP = 2
    OVERSAMPLE_PRES = 2
    MODE = 1

    OVERSAMPLE_HUM = 2
    bus.write_byte_data(addr, REG_CONTROL_HUM, OVERSAMPLE_HUM)

    control = OVERSAMPLE_TEMP << 5 | OVERSAMPLE_PRES << 2 | MODE
    bus.write_byte_data(addr, REG_CONTROL, control)

    cal1 = bus.read_i2c_block_data(addr, 0x88, 24)
    cal2 = bus.read_i2c_block_data(addr, 0xA1, 1)
    cal3 = bus.read_i2c_block_data(addr, 0xE1, 7)

    dig_T1 = getUShort(cal1, 0)
    dig_T2 = getShort(cal1, 2)
    dig_T3 = getShort(cal1, 4)

    dig_P1 = getUShort(cal1, 6)
    dig_P2 = getShort(cal1, 8)
    dig_P3 = getShort(cal1, 10)
    dig_P4 = getShort(cal1, 12)
    dig_P5 = getShort(cal1, 14)
    dig_P6 = getShort(cal1, 16)
    dig_P7 = getShort(cal1, 18)
    dig_P8 = getShort(cal1, 20)
    dig_P9 = getShort(cal1, 22)

    dig_H1 = getUChar(cal2, 0)
    dig_H2 = getShort(cal3, 0)
    dig_H3 = getUChar(cal3, 2)

    dig_H4 = getChar(cal3, 3)
    dig_H4 = (dig_H4 << 24) >> 20
    dig_H4 = dig_H4 | (getChar(cal3, 4) & 0x0F)

    dig_H5 = getChar(cal3, 5)
    dig_H5 = (dig_H5 << 24) >> 20
    dig_H5 = dig_H5 | (getUChar(cal3, 4) >> 4 & 0x0F)

    dig_H6 = getChar(cal3, 6)

    wait_time = (
        1.25
        + (2.3 * OVERSAMPLE_TEMP)
        + ((2.3 * OVERSAMPLE_PRES) + 0.575)
        + ((2.3 * OVERSAMPLE_HUM) + 0.575)
    )
    sleep(wait_time / 1000.0)

    data = bus.read_i2c_block_data(addr, REG_DATA, 8)
    pres_raw = (data[0] << 12) | (data[1] << 4) | (data[2] >> 4)
    temp_raw = (data[3] << 12) | (data[4] << 4) | (data[5] >> 4)
    hum_raw = (data[6] << 8) | data[7]

    var1 = ((((temp_raw >> 3) - (dig_T1 << 1))) * (dig_T2)) >> 11
    var2 = (
        ((((temp_raw >> 4) - (dig_T1)) * ((temp_raw >> 4) - (dig_T1))) >> 12) * (dig_T3)
    ) >> 14
    t_fine = var1 + var2
    temperature = float(((t_fine * 5) + 128) >> 8)

    var1 = t_fine / 2.0 - 64000.0
    var2 = var1 * var1 * dig_P6 / 32768.0
    var2 = var2 + var1 * dig_P5 * 2.0
    var2 = var2 / 4.0 + dig_P4 * 65536.0
    var1 = (dig_P3 * var1 * var1 / 524288.0 + dig_P2 * var1) / 524288.0
    var1 = (1.0 + var1 / 32768.0) * dig_P1
    if var1 == 0:
        pressure = 0
    else:
        pressure = 1048576.0 - pres_raw
        pressure = ((pressure - var2 / 4096.0) * 6250.0) / var1
        var1 = dig_P9 * pressure * pressure / 2147483648.0
        var2 = pressure * dig_P8 / 32768.0
        pressure = pressure + (var1 + var2 + dig_P7) / 16.0

    humidity = t_fine - 76800.0
    humidity = (hum_raw - (dig_H4 * 64.0 + dig_H5 / 16384.0 * humidity)) * (
        dig_H2
        / 65536.0
        * (
            1.0
            + dig_H6 / 67108864.0 * humidity * (1.0 + dig_H3 / 67108864.0 * humidity)
        )
    )
    humidity = humidity * (1.0 - dig_H1 * humidity / 524288.0)
    if humidity > 100:
        humidity = 100
    elif humidity < 0:
        humidity = 0

    return temperature / 100.0, pressure / 100.0, humidity


def autogain_read():
    """Return (voltage, raw) with gain adjustment"""
    global adcGainIdx
    for _ in range(6):
        max_v = 0.0
        max_raw = 0
        for _ in range(100):
            v = abs(chan.voltage)
            r = abs(chan.value)
            if v > max_v:
                max_v = v
            if r > max_raw:
                max_raw = r
        if max_raw > 32000 and ads.gain > 1:
            adcGainIdx = max(0, adcGainIdx - 1)
            ads.gain = ADCgains[adcGainIdx]
            print("gain:", ads.gain)
        elif max_raw < 16000 and ads.gain < 16:
            adcGainIdx = min(4, adcGainIdx + 1)
            ads.gain = ADCgains[adcGainIdx]
            print("gain:", ads.gain)
        else:
            return max_v, max_raw
    return max_v, max_raw


def main():
    global tb, urltime, tensioni, powerPrev

    # connect once
    ipcon = IPConnection()
    ow = BrickletOutdoorWeather(UID, ipcon)
    ipcon.connect(HOST, PORT)

    # if BME280 fails, we can still run
    try:
        chiptemp, air_pressure, humi = readBME280All()
    except Exception as e:
        print("BME280 error:", e)
        chiptemp, air_pressure, humi = 10, 990, 80

    while True:
        # fallback values if BME280 is not read every time
        temperature = chiptemp
        pressure = air_pressure
        humidity = humi

        print("Temperature :", temperature, "C")

        sleep(1)

        # read ADS with autogain
        voltage, byte = autogain_read()
        print("volt:", voltage)
        print("byte:", byte)

        left_shift(tensioni, voltage)
        mean_v = volt_average(tensioni)
        print("mean:", mean_v)

        power = max(230 * (mean_v - 0.0102) * 30 / 1.08, 0)

        if abs(power - powerPrev) < 1000:
            powerPrev = power

        now = time()

        if now - tb > log_int:
            log_write("meteo up")
            tb = now

        if now - urltime > 60:
            urltime = now
            # read weather stations
            try:
                dati = ow.get_station_data(mainStation)
                temp = dati[0] / 10.0
                lastChangeStation = dati[7]
                if lastChangeStation > 1200:
                    temp = -100
            except Exception as e:
                print("Main station error:", e)
                temp = -100

            # sensor 2
            try:
                tMobile = ow.get_sensor_data(sensoreTemperatura2)
                temp2 = tMobile[0] / 10.0
                if tMobile[2] > 1200:
                    temp2 = -100
            except Exception as e:
                print("Sensor 2 error:", e)
                temp2 = -100

            # sensor 1 (ombra)
            try:
                ombra = ow.get_sensor_data(sensoreTemperatura)
                temp1 = ombra[0] / 10.0
                if ombra[2] > 1200:
                    temp1 = -100
                hum_ombra = ombra[1]
            except Exception as e:
                print("Ombra error:", e)
                temp1 = -100
                hum_ombra = -1

            # fan / cpu temp
            tempCpu = temperature_of_raspberry_pi()

            url_string = (
                "https://cesana.steplab.net/carica_dati.php"
                f"?tMobile={temp2}"
                f"&fan={fanMode}"
                f"&tempCpu={tempCpu}"
                f"&temp={temp}"
                f"&humi={dati[1] if 'dati' in locals() else humidity}"
                f"&wind={dati[2]/10.0 if 'dati' in locals() else 0}"
                f"&gust={dati[3]/10.0 if 'dati' in locals() else 0}"
                f"&rain={dati[4]/10.0 if 'dati' in locals() else 0}"
                f"&wdir={dati[5] if 'dati' in locals() else 0}"
                f"&tombra={temp1}"
                f"&hombra={hum_ombra}"
                f"&chip={temperature}"
                f"&pres={round(pressure)}"
                f"&power={int(power)}"
            )
            print(url_string)
            try:
                weburl = urllib.request.urlopen(url_string, timeout=5)
                print("result code:", weburl.getcode())
            except Exception as e:
                print("Connection Error:", e)

    ipcon.disconnect()


if __name__ == "__main__":
    try:
        main()
    finally:
        GPIO.cleanup()
