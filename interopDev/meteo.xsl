<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    
    <xsl:output method="html" encoding="UTF-8" indent="yes"/>
    
    <!-- Template principal -->
    <xsl:template match="/previsions">
        <div class="meteo-container">
            <!-- Sélectionner uniquement les échéances : matin (6h), midi (12h), soir (18h) -->
            <xsl:apply-templates select="echeance[@hour='6' or @hour='12' or @hour='18']"/>
        </div>
    </xsl:template>
    
    <!-- Template pour chaque échéance -->
    <xsl:template match="echeance">
        <div class="meteo-periode">
            <!-- Déterminer la période du jour -->
            <h3>
                <xsl:choose>
                    <xsl:when test="@hour='6'">🌅 Matin</xsl:when>
                    <xsl:when test="@hour='12'">☀️ Midi</xsl:when>
                    <xsl:when test="@hour='18'">🌆 Soir</xsl:when>
                </xsl:choose>
            </h3>
            
            <!-- Extraire la température à 2m (niveau humain) -->
            <xsl:variable name="temp_kelvin" select="temperature/level[@val='2m']"/>
            <!-- Convertir Kelvin en Celsius : °C = K - 273.15 -->
            <xsl:variable name="temp_celsius" select="round($temp_kelvin - 273.15)"/>
            
            <!-- Afficher température avec icône selon la valeur -->
            <div class="meteo-item">
                <xsl:choose>
                    <xsl:when test="$temp_celsius &lt; 5">
                        <span class="icon">🥶</span>
                        <span class="label">Froid : <xsl:value-of select="$temp_celsius"/>°C</span>
                    </xsl:when>
                    <xsl:when test="$temp_celsius &lt; 15">
                        <span class="icon">😐</span>
                        <span class="label">Frais : <xsl:value-of select="$temp_celsius"/>°C</span>
                    </xsl:when>
                    <xsl:when test="$temp_celsius &lt; 25">
                        <span class="icon">☀️</span>
                        <span class="label">Doux : <xsl:value-of select="$temp_celsius"/>°C</span>
                    </xsl:when>
                    <xsl:otherwise>
                        <span class="icon">🔥</span>
                        <span class="label">Chaud : <xsl:value-of select="$temp_celsius"/>°C</span>
                    </xsl:otherwise>
                </xsl:choose>
            </div>
            
            <!-- Pluie : afficher si > 0 -->
            <xsl:variable name="pluie_mm" select="pluie"/>
            <xsl:if test="$pluie_mm &gt; 0">
                <div class="meteo-item">
                    <xsl:choose>
                        <xsl:when test="$pluie_mm &gt; 5">
                            <span class="icon">⛈️</span>
                            <span class="label">Forte pluie : <xsl:value-of select="$pluie_mm"/>mm</span>
                        </xsl:when>
                        <xsl:when test="$pluie_mm &gt; 2">
                            <span class="icon">🌧️</span>
                            <span class="label">Pluie modérée : <xsl:value-of select="$pluie_mm"/>mm</span>
                        </xsl:when>
                        <xsl:otherwise>
                            <span class="icon">🌦️</span>
                            <span class="label">Pluie légère : <xsl:value-of select="$pluie_mm"/>mm</span>
                        </xsl:otherwise>
                    </xsl:choose>
                </div>
            </xsl:if>
            
            <!-- Neige : afficher si risque -->
            <xsl:if test="risque_neige='oui'">
                <div class="meteo-item">
                    <span class="icon">❄️</span>
                    <span class="label">Risque de neige</span>
                </div>
            </xsl:if>
            
            <!-- Vent moyen à 10m -->
            <xsl:variable name="vent_kmh" select="vent_moyen/level[@val='10m']"/>
            <xsl:variable name="rafales_kmh" select="vent_rafales/level[@val='10m']"/>
            <div class="meteo-item">
                <xsl:choose>
                    <xsl:when test="$vent_kmh &gt; 60">
                        <span class="icon">🌪️</span>
                        <span class="label">Vent violent : <xsl:value-of select="round($vent_kmh)"/>km/h</span>
                    </xsl:when>
                    <xsl:when test="$vent_kmh &gt; 40">
                        <span class="icon">💨</span>
                        <span class="label">Vent fort : <xsl:value-of select="round($vent_kmh)"/>km/h</span>
                    </xsl:when>
                    <xsl:when test="$vent_kmh &gt; 20">
                        <span class="icon">🌬️</span>
                        <span class="label">Vent modéré : <xsl:value-of select="round($vent_kmh)"/>km/h</span>
                    </xsl:when>
                    <xsl:otherwise>
                        <span class="icon">🍃</span>
                        <span class="label">Vent léger : <xsl:value-of select="round($vent_kmh)"/>km/h</span>
                    </xsl:otherwise>
                </xsl:choose>
                
                <!-- Afficher les rafales si significatives -->
                <xsl:if test="$rafales_kmh &gt; $vent_kmh + 20">
                    <span class="rafales"> (rafales : <xsl:value-of select="round($rafales_kmh)"/>km/h)</span>
                </xsl:if>
            </div>
            
            <!-- Humidité -->
            <xsl:variable name="humidite_pct" select="humidite/level[@val='2m']"/>
            <xsl:if test="$humidite_pct &gt; 80">
                <div class="meteo-item">
                    <span class="icon">💧</span>
                    <span class="label">Humidité élevée : <xsl:value-of select="round($humidite_pct)"/>%</span>
                </div>
            </xsl:if>
        </div>
    </xsl:template>
    
</xsl:stylesheet>
